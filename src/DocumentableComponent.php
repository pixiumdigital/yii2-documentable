<?php
namespace pixium\documentable;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Exception;
use yii\base\Component;
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
 *       ]
 *   ],
 */
class DocumentableComponent extends Component
{
    /** @var S3Client $s3 */
    public $s3 = null; // 'common\services\AWSComponent'

    /** @var string $s3_bucket_name */
    public $aws_s3_config = null;

    /** @var string $$s3_bucket_name name of the bucket */
    public $s3_bucket_name = 'bucket';

    /**
     * path to upload folder
     * @var string $fs_path
     */
    public $fs_path = '/tmp/upload'; // path

    /**
     * path to temp upload folder
     * @var string $fs_path
     */
    public $fs_path_tmp = '/tmp'; // path

    /**
     *
     */
    public $table_name = 'document';

    /** @var HasherInterface $hasher */
    private $hasher = null;
    public $hasher_class_name = 'pixium\documentable\models\Hasher';

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
}
