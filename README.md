# Add To Calendar

## Add to Composer

Add this repository to your composer.json file

```
"repositories": [
        {
           ...
        },
        {
            "type": "vcs",
            "url": "https://github.com/pixiumdigital/yii2-documentable"
        }
    ],
```

Add the package to the require list
```
"pixium/yii2-documentable": "dev-master"
```

You are good to go !


### Use It

```
use pixium\documentable\AddToCalendar;

<?= AddToCalendar::widget([
        'label' => '<i class="fas fa-calendar-plus"></i>',
        'text' => 'Title Coach',
        'classes' => 'btn-success',
        'add' => 'xxx@gmail.com',
        'start' => 1234567890,
        'duration' => 60,
        'ctz' => 'Asia/Singapore',
        'details' => 'This session has been planned.',
    ]); 
?>
```

## migrations

```sh
php yii migrate
php yii migrate/up --migrationPath=@pixium/documentable/migrations
```

