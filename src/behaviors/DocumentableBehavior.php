<?php
namespace pixium\documentable\behavior;

use yii\db\ActiveRecord;
use yii\base\Behavior;
use pixium\documentable\models\Document;
use pixium\documentable\models\DocumentRel;

/**
 * add to Model
 *   [
 *      'class' => \app\components\document\behaviors\DocumentableBehavior::className(),
 *      'filter' => [
 *          'attribute1' => [
 *            'tag' => 'AVATAR',            // relation_type_tag in document_rel
 *            'multiple' =>  false,         // true, false accept multiple uploads
 *            'replace' => false,           // force replace of existing images
 *            'thumbnail' => false,         // create thumbnails for images
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

            // process this property
            $multiple = $options['multiple'] ?? false;
            if (!$multiple && !empty($files)) {
                // for unique attachments, clear Documents of given tag first
                // clear only if new documents are given (think of the update scenario)
                Document::deleteForModel($model, $options);
            }

            // dump(['files' => $files]);
            // dump(['class' => get_class($files)]);
            // die;
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
        $docrels = DocumentRel::findAll(['rel_type' => $model->tableName(), 'rel_id' => $model->id]);
        foreach ($docrels as $docrel) {
            // DocumentRel->delete() cascades delete to Document.
            $docrel->delete();
        }
    }

    //=== ACCESSORS
    /**
     * get Docs
     *  if no attribute is given get all docs
     * @param string $prop property name
     * @return ActiveQuery array of DocumentRels
     */
    public function getDocs($prop = null)
    {
        $model = $this->owner;
        $rel_type_tag = $this->filter[$prop]['tag'] ?? null;
        $subquery = DocumentRel::find()
            ->select(['document_id'])
            ->where(['rel_type' => $model->tableSchema->name])
            ->andWhere(['rel_id' => $model->id])
            ->andFilterWhere(['rel_type_tag' => $rel_type_tag]);
        return Document::find()
            ->where(['id' => $subquery]);
    }

    // simplify
    /**
     * get DocumentRel attached to this model with tag issued by given property name
     * [DevNote] no point use hasMany as the property is usually given so there
     *   will only rarely be a call like $model->docRels;
     * @param string $prop property name
     * @return ActiveQuery array of DocumentRels
     */
    public function getDocRels($prop = null)
    {
        $model = $this->owner;
        $rel_type_tag = $this->filter[$prop]['tag'] ?? null;
        //  if an attribute is given and it has a rel_type_tag, filter by it
        return DocumentRel::find()
            ->where(['rel_type' => $model->tableSchema->name])
            ->andWhere(['rel_id' => $model->id])
            ->andFilterWhere(['rel_type_tag' => $rel_type_tag]);
    }
}
