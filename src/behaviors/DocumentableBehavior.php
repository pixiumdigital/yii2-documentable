<?php
namespace pixium\documentable\behaviors;

use pixium\documentable\DocumentableException;
use \yii\db\ActiveRecord;
use \yii\base\Behavior;
use \pixium\documentable\models\Document;
use yii\helpers\Html;

/**
 * add to Model
 *   [
 *      'class' => pixium\documentable\behaviors\DocumentableBehavior::className(),
 *      'filter' => [
 *          'attribute1' => [
 *            'tag' => 'AVATAR',            // relation_type_tag in document_rel
 *            'multiple' =>  false,         // true, false accept multiple uploads
 *            'replace' => false,           // force replace of existing images
 *            'thumbnail' => false,         // create thumbnails for images
 *                  thumbnail size is defined in params [
 *                      'thumbnail_size' => [
 *                          'width' => 200, 'height' => 200, // or...
 *                          'square' => 200,
 *                          'crop' => true  // crop will fit the smaller edge in the defined box
 *                       ],
 *                      'quality' => 70, 0-100 smaller generates smaller (and uglier) files used by jpg/webp
 *                      'compression' => 7, 0-10 bigger generates smaller files. used by png
 *                      'thumbnail_background_color' => 'FFF',
 *                      'thumbnail_background_alpha' => 0,
 *                      'thumbnail_type' => 'png',
 *                      // TODO: change thumbnail_size to documentable_thumbnail [ all in ]
 *                      // TODO: add thmubnail definition per documentable
 *                      // make all documentable globl params as well
 *                  ]
 *            'unzip' => true,              // bool or 'unzip' => ['image/png', types to unzip...]
 *
 *            // For Widget only
 *            'mimetypes' => 'image/jpeg,image/png' // csv string of mimetypes
 *            'maxsize' => 500,             // max file size
 *            'extensions' => ['png','jpg'] // array of file extensions without the dot
 *            // advanced
 *            __TODO__ 'on' => ['create', 'update'], // scenarii for attaching
 *          ],
 *      ]
 *   ]
 */
class DocumentableBehavior extends Behavior
{
    /**
     * @property array $filter array of atribute names to process
     */
    public $filter = [];

    /**
     * {@inheritdoc}
     */
    public function events()
    {
        return [
            // ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            // ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            // ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            // ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
            // After deletion of owner model, delete all DocumentRel attach to it and all orphan Documents
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',

            // After creation or update, run afterSave to attach files given to the owner model
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
        ];
    }

    /**
     * after the model has been saved, attach documents based
     * on properties passed (not attributes)
     * @param $event
     */
    public function afterSave($event)
    {
        $model = $this->owner;
        foreach ($this->filter as $prop => $options) {
            if (!$this->owner->hasProperty($prop)) {
                continue;
            }

            // for each prop get file(s), upload it(them)
            // do it here not in the controllers.... simplifies the flow
            $files = \yii\web\UploadedFile::getInstances($model, $prop);
            $model->{$prop} = $files;
            // use property if no tag defined
            if (!isset($options['tag'])) {
                $options['tag'] = $prop;
            }

            // process this property
            $multiple = $options['multiple'] ?? false;
            if (!$multiple && !empty($files)) {
                // for unique attachments, clear Documents of given tag first
                // clear only if new documents are given (think of the update scenario)
                $this->deleteDocs($prop);
                // Document::deleteForModel($model, $options);
            }

            if (!is_array($files)) {
                throw new \yii\base\UserException('DocumentableBehavior afterSave expects an array of files');
            }
            foreach ($files as $file) {
                Document::uploadFileForModel($file, $model, $options);
                if (!$multiple) {
                    // handles the case multiple files where given but only one is required by the model
                    break;
                }
            }
        }
    }

    /**
     * after Delete
     * cleanup the document_rel attached to this model,
     */
    public function afterDelete()
    {
        $model = $this->owner;
        $docs = Document::findAll(['rel_table' => $model->tableName(), 'rel_id' => $model->id]);
        foreach ($docs as $doc) {
            // DocumentRel->delete() cascades delete to Document.
            $doc->delete();
        }
    }

    //=== ACCESSORS
    /**
     * get Docs
     *  if no attribute is given get all docs
     * @param string $prop property name
     * @return ActiveQuery array of Document
     */
    public function getDocs($attribute = null)
    {
        $model = $this->owner;
        $relTypeTag = $this->filter[$attribute]['tag'] ?? $attribute ?? null;
        // throw new Exception('table:'.$model->tableName()." - prop:{$prop} => {$relTypeTag}");
        return Document::find()
            ->andWhere(['rel_table' => $model->tableName()])
            ->andWhere(['rel_id' => $model->id])
            ->andWhere(['rel_type_tag' => $relTypeTag])
            ->orderBy(['rank' => SORT_ASC])
        ;
    }

    /**
     * @param string $prop property name
     * @param array $options html options for img tag
     * @param string $default tag generated if no image is available
     */
    public function getThumbnail($prop, $options = [], $default = null)
    {
        $options['class'] = 'thumbnail '.($options['class'] ?? '');
        if (null !== ($doc1 = $this->getDocs($prop)->one())) {
            /** @var Document doc1 */
            // get thumbnail url
            if (null !== ($url = $doc1->getS3Url(false))) {
                return Html::img($url, $options);
            }
        }
        return (null === $default)
            ? '<div class="'.$options['class'].'"><i class="fa fa-file-image-o fa-3x" aria-hidden="true"></i></div>'
            : $default;
    }

    /**
     * copy docs associated with one attribute to a given model
     * @param string $attribute
     * @param ActiveRecord $model target model to copy to
     * @throws DocumentableException
     */
    public function copyDocs($attribute, $model)
    {
        if (!$model->hasMethod('getDocs')) {
            throw new DocumentableException(DocumentableException::DEXC_NOT_DOCUMENTABLE, 'Target object is not a Documentable');
        }

        $docs = $this->getDocs($attribute)->all();
        foreach ($docs as $doc) {
            /** @var Document $doc */
            $doc->copyToModel($model, $attribute);
        }
    }

    /**
     * mass delete of multiple docs based on attribute name
     * @param string $attribute
     */
    public function deleteDocs($attribute)
    {
        $docs = $this->getDocs($attribute)->all();
        foreach ($docs as $doc) {
            /** @var Document $doc */
            $doc->delete();
        }
    }

    /**
     * upload a file to a Documentable model
     * @param string $attribute on which the filemust be attached
     * @param string $path to upload
     * @throws DocumentableException
     */
    public function uploadFile($attribute, $path)
    {
        $model = $this->owner;
        $options = $this->filter[$attribute] ?? null;
        // ensure attribute is used if no specific tag is defined
        $options['tag'] = $options['tag'] ?? $attribute;
        if (null == $options) {
            throw new DocumentableException(DocumentableException::DEXC_NO_SUCH_ATTRIBUTE, "No Such Attribute: [{$attribute}]");
        }
        // upload file to owner moeel
        Document::uploadFSFileForModel($path, $model, $options);
    }
}
