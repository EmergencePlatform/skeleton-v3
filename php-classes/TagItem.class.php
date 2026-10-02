<?php

class TagItem extends ActiveRecord
{
    public static $tableName = 'tag_items';
    public static $rootClass = self::class;

    public static $fields = [
        'ID' => null
        ,'Class' => null
        ,'ContextClass' => [
            'type' => 'string'
            ,'notnull' => false
        ]
        ,'ContextID' => [
            'type' => 'integer'
            ,'notnull' => false
        ]
        ,'TagID' => [
            'type' => 'integer'
        ]
    ];

    public static $relationships = [
        'Context' => [
            'type' => 'context-parent'
        ]
        ,'Tag' => [
            'type' => 'one-one'
            ,'class' => 'Tag'
        ]
    ];

    public static $indexes = [
        'TagItem' => [
            'fields' => ['TagID','ContextClass','ContextID']
            ,'unique' => true
        ]
    ];



    public function getTitle()
    {
        return sprintf('TagItem %s-%u + %s', $this->ContextClass, $this->ContextID, $this->Tag ? $this->Tag->getTitle() : '[tag not found]');
    }

    public function validate($deep = true)
    {
        // call parent
        parent::validate($deep);

        $this->_validator->validate([
            'field' => 'TagID'
            ,'validator' => 'number'
        ]);

        $this->_validator->validate([
            'field' => 'ContextClass'
            ,'validator' => 'className'
        ]);

        $this->_validator->validate([
            'field' => 'ContextID'
            ,'validator' => 'number'
        ]);

        // save results
        $this->_isValid = $this->_isValid && !$this->_validator->hasErrors();
        if (!$this->_isValid) {
            $this->_validationErrors = array_merge($this->_validationErrors, $this->_validator->getErrors());
        }


        return $this->_isValid;
    }

    public function destroy()
    {
        DB::nonQuery('DELETE FROM `%s` WHERE `%s` = \'%s\' AND `%s` = %u AND `%s` = %u', [
            static::$tableName
            ,static::_cn('ContextClass')
            ,$this->ContextClass
            ,static::_cn('ContextID')
            ,$this->ContextID
            ,static::_cn('TagID')
            ,$this->TagID
        ]);

        return DB::affectedRows() > 0;
    }

    public static function getTagsSummary($options = [])
    {
        $options = array_merge([
            'tagConditions' => []
            ,'itemConditions' => []
            ,'Class' => false
            ,'classConditions' => []
            ,'overlayTag' => false
            ,'order' => 'itemsCount DESC'
            ,'excludeEmpty' => true
            ,'limit' => false
        ], $options);

        // initialize conditions
        $options['tagConditions'] = Tag::mapConditions($options['tagConditions']);

        if (!empty($options['Class'])) {
            $options['classConditions'] = $options['Class']::mapConditions($options['classConditions']);
        }

        $options['itemConditions'] = TagItem::mapConditions($options['itemConditions']);

        // build query
        if (!empty($options['classConditions'])) {
            $classSubquery = 'SELECT `%s` FROM `%s` WHERE (%s)';
            $classParams = [
                $options['Class']::getColumnName('ID')
                ,$options['Class']::$tableName
                ,implode(') AND (', $options['classConditions'])
            ];
        }

        $itemsCountQuery = 'SELECT COUNT(*) FROM `%s` TagItem WHERE TagItem.`%s` = Tag.`%s` AND (%s)';
        $itemsCountParams = [
            TagItem::$tableName
            ,TagItem::getColumnName('TagID')
            ,Tag::getColumnName('ID')
            ,count($options['itemConditions']) > 0 ? implode(') AND (', $options['itemConditions']) : '1'
        ];

        if (!empty($options['overlayTag'])) {
            if (!is_object($OverlayTag = $options['overlayTag']) && !$OverlayTag = Tag::getByHandle($options['overlayTag'])) {
                throw new Exception('Overlay tag not found');
            }

            $itemsCountQuery .= sprintf(
                ' AND (TagItem.`%s`,TagItem.`%s`) IN (SELECT OverlayTagItem.`%s`, OverlayTagItem.`%s` FROM `%s` OverlayTagItem WHERE OverlayTagItem.`%s` = %u)',
                TagItem::getColumnName('ContextClass'),
                TagItem::getColumnName('ContextID'),
                TagItem::getColumnName('ContextClass'),
                TagItem::getColumnName('ContextID'),
                TagItem::$tableName,
                TagItem::getColumnName('TagID'),
                $OverlayTag->ID
            );
        }

        if (isset($classSubquery)) {
            $classIDs = DB::allValues('ID', $classSubquery, $classParams);

            $itemsCountQuery .= sprintf(
                ' AND TagItem.`%s` = "%s" AND TagItem.`%s` IN (%s)',
                TagItem::getColumnName('ContextClass'),
                DB::escape($options['Class']::getStaticRootClass()),
                TagItem::getColumnName('ContextID'),
                count($classIDs) > 0 ? implode(",", $classIDs) : '0'
            );
        }



        $tagConditionsSql = count($options['tagConditions']) > 0 ? implode(') AND (', $options['tagConditions']) : '1';

        if ($options['excludeEmpty']) {
            // Only tags with items are wanted, so count from the items side: one pass
            // over tag_items on its (ContextClass, ContextID) index joined to tags, instead
            // of a correlated COUNT(*) subquery run once for every tag in the table. The
            // item conditions are the ones built above, with the TagID = Tag.ID join
            // moved into the JOIN; the rows and the itemsCount values are the same.
            $itemsCountPrefix = sprintf(
                'SELECT COUNT(*) FROM `%s` TagItem WHERE TagItem.`%s` = Tag.`%s` AND ',
                TagItem::$tableName,
                TagItem::getColumnName('TagID'),
                Tag::getColumnName('ID')
            );
            $preparedItemsCount = DB::prepareQuery($itemsCountQuery, $itemsCountParams);
            if (strpos($preparedItemsCount, $itemsCountPrefix) !== 0) {
                throw new Exception('Unexpected tag items query shape');
            }
            $joinConditions = substr($preparedItemsCount, strlen($itemsCountPrefix));

            $tagSummaryQuery = 'SELECT Tag.*, COUNT(*) AS itemsCount FROM `%s` TagItem JOIN `%s` Tag ON Tag.`%s` = TagItem.`%s` WHERE %s AND (%s) GROUP BY Tag.`%s`';
            $tagSummaryParams = [
                TagItem::$tableName
                ,Tag::$tableName
                ,Tag::getColumnName('ID')
                ,TagItem::getColumnName('TagID')
                ,$joinConditions
                ,$tagConditionsSql
                ,Tag::getColumnName('ID')
            ];
        } else {
            $tagSummaryQuery = 'SELECT Tag.*, (%s) AS itemsCount FROM `%s` Tag WHERE (%s)';
            $tagSummaryParams = [
                DB::prepareQuery($itemsCountQuery, $itemsCountParams)
                ,Tag::$tableName
                ,$tagConditionsSql
            ];
        }

        // add order options
        if ($options['order']) {
            $tagSummaryQuery .= ' ORDER BY '.implode(',', static::_mapFieldOrder($options['order']));
        }

        // add limit options
        if ($options['limit']) {
            $tagSummaryQuery .= sprintf(' LIMIT %u,%u', $options['offset'], $options['limit']);
        }

        try {
            // return indexed table or list
            if ($options['indexField']) {
                return DB::table(Tag::getColumnName($options['indexField']), $tagSummaryQuery, $tagSummaryParams);
            }
            return DB::allRecords($tagSummaryQuery, $tagSummaryParams);
        } catch (TableNotFoundException) {
            return [];
        }
    }
}
