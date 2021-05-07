<?php
namespace pixium\documentable;

use Aws\Exception\AwsException;
use Aws\RetryMiddleware;
use Aws\S3\S3Client;
use Exception;
use yii\base\Component;
use yii\helpers\FileHelper;
use yii\helpers\VarDumper;

/**
 *   Add to config.components
 *   'documentable' => [
 *       'class' => 'pixium\documentable\DocumentableComponent',
 *       'config' => [
 *          'table' => 'document' // database table name used for docments
 *          'aws_credentials' => [ // if defined wil use s3 bucket instead of Filesystem
 *              'key' => '...',
 *              'secret' => '...'
 *          ],
 *          'path' => '/tmp/assets/, // path to save folder
 *          'imageOptions' => [
 *              'max_image_size' => max image size (height or width) (default = 1920)
 *              'quality' => jpeg and webp quality (default = 72)
 *              'jpeg_quality' => jpeg quality uses quality if not set,
 *              'webp_quality' => webp quality uses quality if not set,
 *              'png_compression_level' => png compression (default = 8),
 *              'thumbnail' => [
 *                  'square' => 150 (default)
 *                  'width' => 200, 'height' => 100,
 *                  'crop' => true (crop will fit the smaller edge in the defined box)
 *                  'background_color' => '000',
 *                  'background_alpha' => 0,
 *              ]
 *          ],
 *       ]
 *   ],
 */
class DocumentableComponent extends Component
{
    const THUMBNAILABLE_MIMETYPES = ['image/jpg', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    const RESIZABLE_MIMETYPES = ['image/jpg', 'image/jpeg', 'image/png', 'image/webp'];

    /** @var S3Client $s3 */
    public $s3 = null; // 'common\services\AWSComponent'

    /** @var string $s3_bucket_name */
    public $aws_s3_config = null;

    /** @var string $$s3_bucket_name name of the bucket */
    public $s3_bucket_name = 'bucket';

    /** @var string $fs_path path to upload folder */
    public $fs_path = '/tmp/upload'; // path

    /** @var string $fs_path path to temp upload folder */
    public $fs_path_tmp = '/tmp'; // path

    /** @var string $table_name document table name */
    public $table_name = 'document';

    /** @var HasherInterface $hasher */
    private $hasher = null;
    public $hasher_class_name = 'pixium\documentable\models\Hasher';

    // TODO:
    /**
     * image options []
     *      max_image_size      default 1920
     *
     * ]
     */
    public $imageOptions = [];

    // unadulterated config, overwritten by reflection
    public $config = [
    ];

    public function __construct($config = [])
    {
        // ... initialization before configuration is applied
        // use reflection
        // will map $config keys to attributes
        // e.g. 'version' => 1      mapped to $this->version
        parent::__construct($config);
    }

    /**
     * @param string $path to test
     * @param string $label for the path (upload, upload tmp...)
     * @throws Exception if path is not found, not a dir or not writable
     */
    private function validateFS($path, $label)
    {
        if (!is_dir($path)) {
            throw new Exception("Documentable: '{$path}' {$label} folder not found");
        }
        if (!is_writable($this->fs_path_tmp)) {
            throw new Exception("Documentable: '{$path}' {$label} temp upload folder not writable");
        }
    }

    /**
     * @return bool true: if the component is set with S3
     */
    public function getUsesS3()
    {
        return null !== $this->s3;
    }

    public function init()
    {
        parent::init();

        if (null !== $this->aws_s3_config) {
            if ($name = $this->aws_s3_config['bucket_name'] ?? false) {
                $this->s3_bucket_name = $name;
                // remove bucket name from the config before passing it to S3Client
                unset($this->aws_s3_config['bucket_name']);
            }
            // create bucket handler
            $this->s3 = new \Aws\S3\S3Client($this->aws_s3_config);

            // validate bucket existence
            if (!$this->s3->doesBucketExist($this->s3_bucket_name)) {
                throw new Exception("Documentable: S3 bucket name '{$this->s3_bucket_name}' not found");
            }
        } else {
            // use FS - validate file storage path
            $this->validateFS($this->fs_path, 'upload');
        }

        // dump(['config' => $this->config, 'a' => 'b']);
        // die;
        // set hasher
        $this->hasher = new $this->hasher_class_name();

        //  validate temp folder
        $this->validateFS($this->fs_path_tmp, 'temporary upload');
    }

    /**
     * rotate, resize and recompress file if an image and can be processed
     * @param string $filepath
     * @param string $mimetype
     * @return bool true if processed
     */
    public function processImageFile($filepath, $mimetype, $imageOptions = null)
    {
        // TODO: test if imagine available
        if (!in_array($mimetype, self::RESIZABLE_MIMETYPES)) {
            return false;
        }
        // it's an image to resize!
        // TODO: get from imageOptions
        $path = \Yii::getAlias($filepath);

        // resize
        $max = $this->imageOptions['max_image_size'] ?? 1920;
        $image = \yii\imagine\Image::resize($path, $max, $max);

        // re-orientate
        try {
            $exif = exif_read_data($path);
            // handle EXIF rotation
            switch ($exif['Orientation'] ?? 0) {
            case 3: $image->rotate(180); break;
            case 6: $image->rotate(90); break;
            case 8: $image->rotate(-90); break;
            default: // don't rotate
            }
        } catch (Exception $e) {
            // simply don't reorientate
        }

        // save image to $filePath
        // recompress
        $quality = $this->imageOptions['quality'] ?? 72;
        $image->save($path, [
            'quality' => $quality,
            'jpeg_quality' => $this->imageOptions['jpeg_quality'] ?? $quality,
            'webp_quality' => $this->imageOptions['webp_quality'] ?? $quality,
            'png_compression_level' => $this->imageOptions['png_compression_level'] ?? 8,
        ]);
    }

    /**
     * rotate image then create a png thumbnail
     * @param string $filepathIn
     * @param string $filepathOut
     * @param string $mimetype
     * @return bool true if processed
     */
    public function processImageThumbnail($filepathIn, $filepathOut, $mimetype, $imageOptions = null)
    {
        // TODO: test if imagine available
        if (!in_array($mimetype, self::THUMBNAILABLE_MIMETYPES)) {
            return false;
        }
        // it's an image to resize!
        // TODO: get from imageOptions
        $path = \Yii::getAlias($filepathIn);

        // resize
        $thumbnailSize = $this->imageOptions['thumbnail_size'] ?? ['square' => 150];
        $wmax = $thumbnailSize['width'] ?? $thumbnailSize['square'];
        $hmax = $thumbnailSize['height'] ?? $thumbnailSize['square'];
        $crop = $thumbnailSize['crop'] ?? false;
        $bgColor = $thumbnailSize['background_color'] ?? '000';
        $bgAlpha = $thumbnailSize['background_alpha'] ?? 0;

        $quality = $this->imageOptions['quality'] ?? 72;

        \yii\imagine\Image::$thumbnailBackgroundColor = $bgColor;
        \yii\imagine\Image::$thumbnailBackgroundAlpha = $bgAlpha;
        \yii\imagine\Image::thumbnail(
            $filepathIn,
            $wmax,
            $hmax,
            //\Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND // crop
            $crop ? \Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND : \Imagine\Image\ImageInterface::THUMBNAIL_INSET,
        )->save($filepathOut, [
            'jpeg_quality' => $this->imageOptions['jpeg_quality'] ?? $quality,
            'webp_quality' => $this->imageOptions['webp_quality'] ?? $quality,
            'png_compression_level' => $this->imageOptions['png_compression_level'] ?? 8,
        ]);
    }

    /**
     * saves file on FS or S3
     * @param string $filename target (e.g. cca3dfd5-lasagna5.jpg)
     * @param string $filepath src (path to file)
     * @param string $mimetype
     */
    public function saveFile($filename, $filepath, $mimetype = null)
    {
        if (null !== $this->s3) {
            // move it to the S3 bucket
            $s3FileOptions = [
                'Bucket' => $this->s3_bucket_name,
                'Key' => $filename
            ];

            $result = $this->s3->putObject(array_merge($s3FileOptions, [
                'SourceFile' => $filepath,
                // needed for SVGs
                'ContentType' => $mimetype,
                'Metadata' => [
                    'version' => '1.0.0'
                ]
            ]));
            // poll object until it is accessible
            $this->s3->waitUntil('ObjectExists', $s3FileOptions);
            // ERASE tmp thumbnail file
            FileHelper::unlink($filepath);
            return true;
        }
        // FS move
        rename($filepath, "{$this->fs_path}/{$filename}");
        return true;
    }

    /**
     * delete file
     * @param string $filename target (e.g. cca3dfd5-lasagna5.jpg)
     */
    public function deleteFile($filename)
    {
        if (null !== $this->s3) {
            $cmd = $this->s3->deleteObject([
                'Bucket' => $this->s3_bucket_name,
                'Key' => $filename,
                // 'VersionId' => 'string',
            ]);
        } else {
            //FS
            $filepath = "{$this->fs_path}/{$filename}";
            FileHelper::unlink($filepath);
        }
    }
}
