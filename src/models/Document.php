<?php

namespace pixium\documentable\models;

use Exception;
use Yii;
// imagine to create thumbs out of images
use \yii\imagine\Image;
use \yii\helpers\FileHelper;
use \yii\db\ActiveRecord;
use yii\web\UploadedFile;

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
    const THUMBNAILABLE_MIMETYPES = ['image/jpg', 'image/jpeg', 'image/png', 'image/webp'];

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
            [['title', 'url_master', 'rel_table', 'rel_id'], 'required'],
            [['size', 'rank', 'rel_id', 'created_at', 'created_by', 'updated_at', 'updated_by'], 'integer'],
            [['rel_table', 'rel_type_tag'], 'string'],
            [['title', 'url_master', 'url_thumb'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'rel_id' => 'Rel ID',
            'rel_table' => 'Rel Type (ID)',
            'rel_type_tag' => 'Rel Type Tag (Type ID)',
            'rank' => 'Rank',
            'title' => 'Title',
            'url_thumb' => 'Url Thumb',
            'url_master' => 'Url Original',
            'size' => 'Size',
            'created_at' => 'Created At',
            'created_by' => 'Created By',
            'updated_at' => 'Updated At',
            'updated_by' => 'Updated By',
        ];
    }

    //=== ACCESSORS
    // GET model linked to a document by
    /**
     * @return string classna
     */
    public function getRelClassName()
    {
        $tablesplit = array_map('ucfirst', explode('_', $this->rel_table));
        $classname = implode('', $tablesplit);
        return "\\app\\models\\${classname}";
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getRelModel()
    {
        // return object
        return $this->hasOne($this->relClassName, ['id' => 'rel_id']);
    }

    //=== EVENTS
    /**
     * before save
     * set the rank to the current number of of rels in this group
     * @inheritdoc
     */
    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            // set the rank (in insert case)
            if ($insert) {
                $this->rank = self::find()->where([
                    'rel_table' => $this->rel_table,
                    'rel_id' => $this->rel_id,
                ])->andFilterWhere([
                    'rel_type_tag' => $this->rel_type_tag
                ])->count();
            }
            return true;
        }
        return false;
    }

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
        // return empty($this->documentRel);
    }

    /**
     * delete
     * proper delete also removes ressources from S3
     */
    public function delete()
    {
        // update rank
        if ($this->rank !== null) {
            // decrease all ranks higher than the current one by 1
            $this->moveFromRankTo($this->rank);
        }

        // __TODO__ delete from S3
        $s3 = Yii::$app->aws->s3;
        $bucket = Yii::$app->params['S3BucketName'] ?? 'no-bucket-specified';
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

    //=== SPECIAL
    /**
     * move from rank to
     * move from rank iFrom to iTo (target)
     * C:max O(2)
     */
    public function moveFromRankTo($iFrom, $iTo = null)
    {
        // 0 1 2 3 4 5 6 7
        // 0 2[1]3...       2 moved from 2 to 1     -> [ +1 ] for 1 to 2, then set 1
        //   ^
        // 3[0 1 2]4...     3 moved from 3 to 0     -> [ +1 ] for 0 to 3, then set 0
        // ^
        // 0[2 3 4]1 5      1 moved to 4            -> [ -1 ]
        //         ^
        // 0 1[2 3 4 5...]  1 deleted (no iTo)      -> [ -1 ]
        //   ^
        $iTarget = $iTo; // save target from ordering
        $op = '-';
        if (($iTo !== null) && ($iFrom > $iTo)) {
            // ensure iFrom < iTo always
            //swap($iFrom, $iTo);
            $iTmp = $iFrom;
            $iFrom = $iTo;
            $iTo = $iTmp;
            $op = '+';
        }
        // base query
        $paramsBound = [
            'rel_table' => $this->rel_table,
            'rel_id' => $this->rel_id,
            'rank_from' => $iFrom
        ];
        $tn = self::tableName();
        $sql = "UPDATE `{$tn}` SET `rank`=`rank`{$op}1 WHERE `rel_table`=:rel_table AND `rel_id`=:rel_id AND `rank` IS NOT NULL AND `rank`>=:rank_from";
        // extra filters
        if ($iTo !== null) {
            // add filter to rank iTo  (<= note!)
            $paramsBound['rank_to'] = $iTo;
            $sql .= ' AND `rank`<=:rank_to';
        }
        if ($this->rel_type_tag != null) {
            // to do it yii style and protect params
            $paramsBound['rel_type_tag'] = $this->rel_type_tag;
            $sql .= ' AND `rel_type_tag`=:rel_type_tag';
        }
        \Yii::$app->db->createCommand($sql, $paramsBound)->execute();

        // if a target is specified, update the rank of the target
        if ($iTarget !== null) {
            // finally set rank for the moved one
            $this->rank = $iTarget;

            if ($this->save(false, ['rank'])) {
                return true;
            }
            $this->addError('rank', "Can't assign rank to document:{$this->id}");
            return false;
        }
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
            'Bucket' => Yii::$app->params['S3BucketName'],
            'Key' => ($s3FileId),
        ]);

        $request = $s3->createPresignedRequest($cmd, '+20 minutes');

        // Get the actual presigned-url
        $presignedUrl = (string) $request->getUri();

        //doesn't work liket this anymore
        //$signedUrl = $s3->getObjectUrlYii::$app->params['S3BucketName'], $this->url_master, '+10 minutes');
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
            'Bucket' => Yii::$app->params['S3BucketName'],
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
        $rels = Document::find()
            ->where(['rel_id' => $relId])
            ->andWhere(['rel_table' => $relType])
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
        $bucketOptions = ['Bucket' => Yii::$app->params['S3BucketName']];
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
        if (in_array($mimetype, self::THUMBNAILABLE_MIMETYPES)) {
            // it's an image to resize!
            $max = Yii::$app->params['max_image_size'] ?? 1920;
            $quality = Yii::$app->params['quality'] ?? 70; // smaller number smaller files
            $compression = Yii::$app->params['compression'] ?? 7; // larger number smaller files

            //  $image, $width, $height, $keepAspectRatio = true, $allowUpscaling = false
            $path = Yii::getAlias($filepath);
            \yii\imagine\Image::resize($path, $max, $max)->save($path, [
                'quality' => $quality,
                'jpeg_quality' => $quality,
                'webp_quality' => $quality,
                'png_compression_level' => $compression,
            ]);
            //$filesize = filesize($path);

            // THUMBNAIL: (preview) generate thumbnail and $s3Thumbfilename
            // if svg, use the same file, continue
            if ($options['thumbnail'] ?? false) {
                // find the extension, remove it, insert `.thumb`
                // png, jpeg: generate thumbnail, use it
                $thumbExtension = Yii::$app->params['thumbnail_type'] ?? $extension;
                $thumbfilename = "/tmp/{$basename}_thumb.{$thumbExtension}";
                $s3Thumbfilename = "{$hash}-{$basename}.thumb.{$thumbExtension}";
                // $s3Thumbfilename = substr($s3Filename, 0, -strlen($extension))."thumb.{$extension}";
                $wh = Yii::$app->params['thumbnail_size'];
                $w = $wh['width'] ?? 150;
                $h = $wh['height'] ?? 150;
                // SQUARE shortcut: use 'square'=150
                if ($xy = ($wh['square'] ?? false)) {
                    $w = $xy;
                    $h = $xy;
                }
                $fittingModel = isset($wh['crop'])
                    ? \Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND
                    : \Imagine\Image\ImageInterface::THUMBNAIL_INSET;
                // min-width / min-height
                // if ($mw = ($wh['min_width'] ?? false)) {
                //     // fix height EXPLORE: h=null)
                // }
                // if ($mh = ($wh['min_height'] ?? false)) {
                //     // fix width (EXPLORE: w=null)
                // }

                \yii\imagine\Image::$thumbnailBackgroundColor = Yii::$app->params['thumbnail_background_color'] ?? '000';
                \yii\imagine\Image::$thumbnailBackgroundAlpha = Yii::$app->params['thumbnail_background_alpha'] ?? 0;
                \yii\imagine\Image::thumbnail(
                    $filepath,
                    $w,
                    $h,
                    //\Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND // crop
                    $fittingModel // contain
                )->save($thumbfilename, [
                    'jpeg_quality' => $quality,
                    'webp_quality' => $quality,
                    'png_compression_level' => $compression,
                ]);

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
            // } else {
            //DBG:
            //throw new Exception("WDF: mime: {$mimetype}");
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
        // get the size after eventual compression
        $filesizeFinal = filesize($filepath);
        // ERASE tmp file
        FileHelper::unlink($filepath);

        // Create `document`
        $model = new Document([
            'rel_table' => $relType,
            'rel_id' => $relId,
            'rel_type_tag' => $relTag,
            'url_master' => $s3Filename, // AWS S3 key for master file
            'url_thumb' => $s3Thumbfilename, // AWS S3 key for thumbnail file
            'title' => $filename,
            'size' => $filesizeFinal, // in Bytes (save to be able to know how much data this user uses)
        ]);
        if (!$model->save() && ($errors = $model->errors)) {
            // throw exception couldn't save document
            $err = empty($errors) ? 'unknown reason' : $errors[0][0];
            throw new yii\db\Exception("Couldn't Save `document` reason:{$err}");
        }
        // success, return document id
        return $model;
    }

    /**
     * helper 1x file only
     * upload File For a given model
     * - handle zips
     * @param UploadedFile $file
     * @param ActiveRecord $model
     * @param array $options
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

    /**
     * For Console uploads to bucket (and fixtures)
     * @param string $path
     * @param ActiveRecord $model
     * @param array $options
     */
    public static function uploadFSFileForModel($path, $model, $options = [])
    {
        $path_parts = pathinfo($path);
        $tempPath = "/tmp/{$path_parts['basename']}"; // use temp to avoid deletion after upload
        copy($path, $tempPath);
        self::_uploadFile(
            $tempPath, // path in FS
            $path_parts['filename'], // no path no extension
            $path_parts['extension'],
            FileHelper::getMimeType($path),
            filesize($path),
            $model->id,
            $model->tableName(),
            $options['tag'] ?? null,
            $options
        );
    }
}//eo-class
