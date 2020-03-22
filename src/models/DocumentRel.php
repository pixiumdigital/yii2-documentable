<?php

namespace pixium\documentable\models;

use Yii;

/**
 * This is the model class for table "document_rel".
 *
 * @property int $id
 * @property int $document_id
 * @property int $rel_id                used to identify the object related to the document
 * @property string $rel_type           used to differentiate types of objects to attach the document to
 * @property string $rel_type_tag       used to differentiate types of documents
 * @property int $rank                  used to display galleries (order)
 * @property int $created_at
 * @property int $created_by
 * @property int $updated_at
 * @property int $updated_by
 *
 * @property Document $document
 */
class DocumentRel extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'document_rel';
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
            [['document_id', 'rel_id', 'rel_type'], 'required'],
            [['document_id', 'rel_id', 'rank', 'created_at', 'created_by', 'updated_at', 'updated_by'], 'integer'],
            [['rel_type', 'rel_type_tag'], 'string'],
            [['document_id'], 'exist', 'skipOnError' => true, 'targetClass' => Document::className(), 'targetAttribute' => ['document_id' => 'id']]
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => t('ID'),
            'document_id' => t('Document ID'),
            'rel_id' => t('Rel ID'),
            'rel_type' => t('Rel Type (ID)'),
            'rel_type_tag' => t('Rel Type Tag (Type ID)'),
            'rank' => t('Rank'),
            'created_at' => t('Created At'),
            'created_by' => t('Created By'),
            'updated_at' => t('Updated At'),
            'updated_by' => t('Updated By'),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getDocument()
    {
        return $this->hasOne(Document::className(), ['id' => 'document_id']);
    }

    // GET model linked to a document by
    /**
     * @return string classna
     */
    public function getRelClassName()
    {
        $tablesplit = array_map('ucfirst', explode('_', $this->rel_type));
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
     */
    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            // call the rank (in insert case)
            if ($insert) {
                $this->rank = self::find()->where([
                    'rel_type' => $this->rel_type,
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
            'rel_type' => $this->rel_type,
            'rel_id' => $this->rel_id,
            'rank_from' => $iFrom
        ];
        $tn = self::tableName();
        $sql = "UPDATE `{$tn}` SET `rank`=`rank`{$op}1 WHERE `rel_type`=:rel_type AND `rel_id`=:rel_id AND `rank` IS NOT NULL AND `rank`>=:rank_from";
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
            $this->addError('rank', "Can't assign rank to document_Rel{$this->id}");
            return false;
        }
    }

    /**
     * delete
     * after deleting the relationship between `document` and `table`
     * try to delete the document - do it in delete because the document_id is required
     */
    public function delete()
    {
        $document = $this->document;
        // if there are ranked rels, update other rels
        if ($this->rank !== null) {
            // decrease all ranks higher than the current one by 1
            $this->moveFromRankTo($this->rank);
        }
        $res = parent::delete();
        // now try to delete the document
        // (won't happen if there are other document_rel record pointiing to it)

        $document->delete();
        return $res;
    }
}
