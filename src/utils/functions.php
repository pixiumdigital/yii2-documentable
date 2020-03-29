<?php

use yii\helpers\ArrayHelper;
use Yii;

if (!function_exists('param')) {
    function param($name, $default = null)
    {
        return ArrayHelper::getValue(Yii::$app->params, $name, $default);
    }
}
if (!function_exists('dump')) {
    function dump()
    {
    }
}
