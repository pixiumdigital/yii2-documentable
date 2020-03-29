<?php

namespace pixium\documentable\models;

use Yii;
// imagine to create thumbs out of images
use \yii\imagine\Image;
use \yii\helpers\FileHelper;
use \yii\db\ActiveRecord;

require_once __DIR__ . "/../utils/functions.php";

// use yii]imagine\

//use Imagine\Image\ManipulatorInterface;

/**
 * This is the model class for table "document".
 *
 * @property int $id
 * @property string $title
 * @property string $url_thumb
 * @property string $url_master
 * @property int $created_at
 * @property int $created_by
 * @property int $updated_at
 * @property int $updated_by
 */
class Document extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'document';
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            ['class' => \yii\behaviors\TimestampBehavior::className()],
            ['class' => \yii\behaviors\BlameableBehavior::className()],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['title', 'url_master'], 'required'],
            [['size', 'created_at', 'created_by', 'updated_at', 'updated_by'], 'integer'],
            [['title', 'url_master', 'url_thumb'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => t('ID'),
            'title' => t('Title'),
            'url_thumb' => t('Url Thumb'),
            'url_master' => t('Url Original'),
            'size' => t('Size'),
            'created_at' => t('Created At'),
            'created_by' => t('Created By'),
            'updated_at' => t('Updated At'),
            'updated_by' => t('Updated By'),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getDocumentRel()
    {
        return $this->hasMany(DocumentRel::className(), ['document_id' => 'id']);
    }

    //=== EVENTS
    /**
     * before Delete
     */
    public function beforeDelete()
    {
        if (!parent::beforeDelete()) {
            return false;
        }
        // check that there isn't any document_rel linked to this document
        // accept delete only if no relation exists
        return empty($this->documentRel);
    }

    /**
     * delete
     * proper delete also removes ressources from S3
     */
    public function delete()
    {
        // __TODO__ delete from S3
        $s3 = Yii::$app->aws->s3;
        $bucket = param('S3BucketName');
        $cmd = $s3->deleteObject([
            'Bucket' => $bucket,
            'Key' => $this->url_master,
            // 'VersionId' => 'string',
        ]);
        // delete thumbnail as well if it exists
        if (
            $this->url_thumb
            && ($this->url_thumb != $this->url_master)
        ) {
            $cmd = $s3->deleteObject([
                'Bucket' => $bucket,
                'Key' => $this->url_thumb,
                // 'VersionId' => 'string',
            ]);
        }

        // remove DB record
        return parent::delete();
    }

    //=== AWS S3
    /**
     * get presigned url valid for the next 10 minutes
     */
    public function getS3Url($returnsMaster = true)
    {
        $s3FileId = $returnsMaster ? $this->url_master : $this->url_thumb;
        if ($s3FileId == null) {
            return null;
        }
        $s3 = Yii::$app->aws->s3;
        //Creating a presigned URL
        $cmd = $s3->getCommand('GetObject', [
            'Bucket' => param('S3BucketName'),
            'Key' => ($s3FileId),
        ]);

        $request = $s3->createPresignedRequest($cmd, '+20 minutes');

        // Get the actual presigned-url
        $presignedUrl = (string) $request->getUri();

        //doesn't work liket this anymore
        //$signedUrl = $s3->getObjectUrl(param('S3BucketName'), $this->url_master, '+10 minutes');
        return $presignedUrl;
    }

    /**
     * getS3Object
     * returns the complete object
     */
    public function getS3Object($returnsMaster = true)
    {
        $s3FileId = $returnsMaster ? $this->url_master : $this->url_thumb;
        if ($s3FileId == null) {
            return null;
        }
        $s3 = Yii::$app->aws->s3;

        $config = [
            'Bucket' => param('S3BucketName'),
            'Key' => ($s3FileId),
        ];

        //Creating a presigned URL
        $result = $s3->getObject($config);

        // Display the object in the browser.
        //header("Content-Type: {$result['ContentType']}");
        return $result['Body'];
    }

    /**
     * delete all Documents and DocumentRels linked to a model
     * by
     * @param integer relId id of model linked to
     * @param string relType type of rel ('article')
     * @param string relTag // don't give it and it destroys everything!
     */
    public static function deleteAllRelTo($relId, $relType, $relTag = null)
    {
        $rels = DocumentRel::find()
            ->where(['rel_id' => $relId])
            ->andWhere(['rel_type' => $relType])
            ->andFilterWhere(['rel_type_tag' => $relTag])
            ->all();
        foreach ($rels as $rel) {
            // delete each DocumentRel, and Document if no Rel is attached to it
            $res = $rel->delete();
        }
    }

    /**
     *
     */
    public static function deleteForModel($model, $options = [])
    {
        $rel_type_tag = $options['tag'] ?? null;
        self::deleteAllRelTo($model->id, $model->tableName(), $rel_type_tag);
    }

    /**
     * upload one file given by FS path to s3
     * also generates thumbnail if possible and required
     * (add: encrypt the file)
     * FS
     * @param string filepath - location of file in FS
     * @param string basename - filename, no path, no extension
     * @param string extension - filename extension only
     * @param string mimetype
     * @param integer filesize
     * DB
     * @param integer relId id of model to link to
     * @param string relType type of rel ('article')
     * @param string relTag tag attached to the relationship
     * Options
     * @param Array options as key => value from DocumentableBehavior
     */
    private static function _uploadFile(
        $filepath,
        $basename,
        $extension,
        $mimetype,
        $filesize,
        $relId,
        $relType,
        $relTag,
        $options
    ) {
        $now = time();
        // filename is the base filename with extension (no path info attached)
        $filename = "{$basename}.{$extension}"; // I know it stll accepts "file."
        $hash = substr(md5("{$filename}{$now}"), 0, 8);
        $s3Filename = "{$hash}-{$filename}";
        $s3Thumbfilename = null;

        // put it on S3
        $s3 = Yii::$app->aws->s3;
        $bucketOptions = ['Bucket' => param('S3BucketName')];
        // dump([
        //     // 's3endpoint' => $s3->getEndpoint(),
        //     // 's3region' => $s3->getRegion(),
        //     'path' => $filepath, // /tmp/dgsjdgkd
        //     'basename' => $basename,
        //     'extension' => $extension,
        //     'name' => $filename, // myfile.svg
        //     's3Filename' => $s3Filename
        // ]);
        // die;

        // MASTER: resize image to get to the max allowed webapp size 'max_image_size'
        if (in_array($mimetype, ['image/jpeg', 'image/png'])) {
            // it's an image to resize!
            $max = param('max_image_size', 1920);
            //  $image, $width, $height, $keepAspectRatio = true, $allowUpscaling = false
            \yii\imagine\Image::resize(Yii::getAlias($filepath), $max, $max)->save();

            // THUMBNAIL: (preview) generate thumbnail and $s3Thumbfilename
            // if svg, use the same file, continue
            if ($options['thumbnail'] ?? false) {
                // find the extension, remove it, insert `.thumb`
                // png, jpeg: generate thumbnail, use it
                $thumbfilename = "/tmp/{$basename}_thumb.{$extension}";
                $s3Thumbfilename = "{$hash}-{$basename}.thumb.{$extension}";
                // $s3Thumbfilename = substr($s3Filename, 0, -strlen($extension))."thumb.{$extension}";
                $wh = param('thumbnail_size');

                \yii\imagine\Image::$thumbnailBackgroundColor = '000';
                \yii\imagine\Image::$thumbnailBackgroundAlpha = 0;
                \yii\imagine\Image::thumbnail(
                    $filepath,
                    $wh['width'] ?? 200,
                    $wh['height'] ?? 200,
                    //\Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND // crop
                    \Imagine\Image\ImageInterface::THUMBNAIL_INSET // contain
                )->save($thumbfilename, ['quality' => 80]);

                // upload to bucket
                $s3FileOptions = array_merge($bucketOptions, ['Key' => $s3Thumbfilename]);
                $result = $s3->putObject(array_merge($s3FileOptions, [
                    'SourceFile' => $thumbfilename,
                    // needed for SVGs
                    'ContentType' => $mimetype,
                    'Metadata' => [
                        'version' => '1.0.0'
                    ]
                ]));
                // poll object until it is accessible
                $s3->waitUntil('ObjectExists', $s3FileOptions);
                // ERASE tmp thumbnail file
                FileHelper::unlink($thumbfilename);
            }
        } elseif ($mimetype == 'image/svg+xml') {
            // SVG MASTER = THUMBNAIL
            $s3Thumbfilename = $s3Filename;
        }

        // MASTER - upload to s3
        $s3FileOptions = array_merge($bucketOptions, ['Key' => $s3Filename]);
        $result = $s3->putObject(array_merge($s3FileOptions, [
            'SourceFile' => $filepath,
            // needed for SVGs
            'ContentType' => $mimetype,
            'Metadata' => [
                'version' => '1.0.0',
                'tag' => $relTag ?? '', //tag object in bucket too
            ]
        ]));
        // poll object until it is accessible
        $s3->waitUntil('ObjectExists', $s3FileOptions);
        // ERASE tmp file
        FileHelper::unlink($filepath);

        // Create `document`
        $model = new Document([
            'url_master' => $s3Filename, // AWS S3 key for master file
            'url_thumb' => $s3Thumbfilename, // AWS S3 key for thumbnail file
            'title' => $filename,
            'size' => $filesize, // in Bytes (save to be able to know how much data this user uses)
        ]);
        if (!$model->save() && ($errors = $model->errors)) {
            // throw exception couldn't save document
            $err = empty($errors) ? 'unknown reason' : $errors[0][0];
            throw new yii\db\Exception("Couldn't Save `document` reason:{$err}");
        }
        // create `document_rel` to attach uploaded doc to user
        $rel = new DocumentRel([
            'document_id' => $model->id,
            'rel_type' => $relType,
            'rel_id' => $relId,
            'rel_type_tag' => $relTag,
        ]);
        // $rel->document_id = $model->id;
        // $rel->setTargetId($relId);
        if (!$rel->save() && ($errors = $rel->errors)) {
            // throw exception couldn't save document
            $err = empty($errors) ? 'unknown reason' : $errors[0][0];
            throw new yii\db\Exception("Couldn't Save `document_rel` reason:{$err}");
        }
        // success, return document id
        return $model;
    }

    /**
     * helper 1x file only
     * upload File For a given model
     * - handle zips
     */
    public static function uploadFileForModel($file, $model, $options = [])
    {
        $filename = "/tmp/{$file->baseName}.{$file->extension}";
        $file->saveAs($filename); // guzzle the file
        // dump([
        //     'class' => get_class($file),
        //     'filename' => $filename,
        //     'file' => $file,
        // ]);
        // dump(['options' => $options]);
        // die;

        $rel_type_tag = $options['tag'] ?? null;

        // ZIP?
        $acceptedZipTypes = ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip', 'application/x-compressed'];
        $unzip = $options['unzip'] ?? false;
        if (($unzip !== false)
            && in_array($file->type, $acceptedZipTypes)
        ) {
            // unzip and if unzip is an array, use the array to filter the mimetypes to extract
            // if true extract all
            // dump(['zipped', 'file' => $file, 'options' => $options]);
            // die;
            $zip = new \ZipArchive();
            $res = $zip->open($filename);
            // save files to process in order
            if ($res === true) {
                // Unzip the Archive.zip and upload it to s3
                $zipBasePath = '/tmp/unzip';
                $zip->extractTo($zipBasePath);
                $unzipFiles = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    // loop through the archive
                    $stat = $zip->statIndex($i);
                    $filepath = "{$zipBasePath}/{$stat['name']}"; // "setA/a.txt"
                    $filesize = $stat['size'];
                    $mimetype = mime_content_type($filepath); // text/plain
                    if (($filesize > 0)
                        && ((true === $unzip) || (is_array($unzip) && in_array($mimetype, $unzip)))
                    ) {
                        $unzipFiles[$filepath] = [
                            // 'filepath' => $filepath,
                            'filesize' => $filesize,
                            'mimetype' => $mimetype
                        ];
                    }
                }
                // now sort the files alphabetically
                ksort($unzipFiles, SORT_ASC);
                // and finally save them
                foreach ($unzipFiles as $filepath => $uzf) {
                    // Extractable file, (not directory)
                    $extension = pathinfo($filepath, PATHINFO_EXTENSION); // txt
                    $basename = pathinfo($filepath, PATHINFO_FILENAME); // a
                    self::_uploadFile(
                        $filepath,
                        $basename,
                        $extension,
                        $uzf['mimetype'],
                        $uzf['filesize'], // FS
                        $model->id,
                        $model->tableName(),
                        $rel_type_tag,
                        $options // options
                    );
                }
                // delete zip folder
                FileHelper::removeDirectory($zipBasePath);
                //rmdir($zipBasePath);
            }
            // delete zip file
            // as it won't be done after upload by _uploadFile
            FileHelper::unlink($filename);
            return;
        }

        // not a zip
        self::_uploadFile(
            $filename,
            $file->baseName,
            $file->extension,
            $file->type,
            $file->size,
            $model->id,
            $model->tableName(),
            $rel_type_tag,
            $options
        );

        // self::uploadFile($file, $model->id, $model->tableName(), $rel_type_tag, $options);
    }
}//eo-class
