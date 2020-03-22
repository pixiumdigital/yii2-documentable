<?php
namespace pixium\documentable;

use yii\base\Widget;

use yii\bootstrap\ButtonDropdown;

/**
 * Alert widget renders a dropdown to add an event to a calendar
 *
 * ```php
 * AddToCalendar::widget([
 *       'label' => '<i class="fas fa-calendar-plus"></i>',
 *       'text' => 'Coaching Session',
 *       // 'classes' => 'btn-success',
 *       // 'add' => 'test',
 *       'start' => $session->time,
 *       'duration' => $session->time_duration,
 *       'ctz' => me()->time_zone,
 *       'details' => 'This session has been planned.',
 *   ]);
 * ```
 * @author Remi Tache <remi.tache@pixiumdigital.com>
 */
class AddToCalendar extends \yii\bootstrap\Widget
{
    /*
    anchor address:
    http://www.google.com/calendar/event?
    This is the base of the address before the parameters below.
    */

    /*
    label of the button
    */
    public $label = 'Add To Calendar';

    /*
    CSS class for the dropdown
    */
    public $classes = '';

    /*
    action:
    action=TEMPLATE
    A default required parameter.
    */
    public $action;

    /*
    src:
    Example: src=default%40gmail.com
    Format: src=text
    This is not covered by Google help but is an optional parameter
    in order to add an event to a shared calendar rather than a user's default.
    */
    public $src;

    /*
    text:
    Example: text=Garden%20Waste%20Collection
    Format: text=text
    This is a required parameter giving the event title.
    */
    public $text;

    /*
    dates:
    Example: dates=20090621T063000Z/20090621T080000Z
           (i.e. an event on 21 June 2009 from 7.30am to 9.0am
            British Summer Time (=GMT+1)).
    Format: dates=YYYYMMDDToHHMMSSZ/YYYYMMDDToHHMMSSZ
           This required parameter gives the start and end dates and times
           (in Greenwich Mean Time) for the event.
    */
    public $start;
    public $duration;
    public $ctz;

    /*
    details:
    Exanple: This session is about ...
    */
    public $details;

    /*
    location:
    Example: location=Home
    Format: location=text
    The obvious location field.
    */
    public $location;

    /*
    trp:
    Example: trp=false
    Format: trp=true/false
    Show event as busy (true) or available (false)
    */
    public $trp;

    /*
    sprop:
    Example: sprop=http%3A%2F%2Fwww.me.org
    Example: sprop=name:Home%20Page
    Format: sprop=website and/or sprop=name:website_name
    */
    public $sprop;

    /*
    add:
    Example: add=default%40gmail.com
    Format:  add=guest email addresses
    */
    public $add;

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $google_url = 'https://calendar.google.com/calendar/r/eventedit?';
        $outlook_url = 'https://outlook.live.com/owa/?path=/calendar/action/compose&rru=addevent&';
        $yahoo_url = 'https://calendar.yahoo.com/?v=60&view=d&type=20&';

        if ($this->text) {
            $google_url .= 'text='.urlencode($this->text).'&';
            $outlook_url .= 'subject='.urlencode($this->text).'&';
            $yahoo_url .= 'title='.urlencode($this->text).'&';
        }

        if ($this->location) {
            $google_url .= 'location='.urlencode($this->location).'&';
            $outlook_url .= 'location='.urlencode($this->location).'&';
            $yahoo_url .= 'in_loc='.urlencode($this->location).'&';
        }

        if ($this->start && $this->duration) {
            $formated_start = date('Ymd\THis', $this->start);
            $formated_end = date('Ymd\THis', strtotime('+'.$this->duration.' minutes', $this->start));
            // dates=20090621T063000Z/20090621T080000Z
            $google_url .= 'dates='.urlencode($formated_start).'/'.urlencode($formated_end).'&';
            $google_url .= 'ctz='.urlencode($this->ctz).'&';

            $outlook_url .= 'startdt='.urlencode($formated_start).'&';
            $outlook_url .= 'enddt='.urlencode($formated_end).'&';

            $yahoo_url .= 'st='.urlencode($formated_start).'&';
            $yahoo_url .= 'et='.urlencode($formated_end).'&';
        }
        // &ctz=Asia/Singapore

        if ($this->add) {
            $google_url .= 'add='.urlencode($this->add).'&';
        }

        if ($this->details) {
            $google_url .= 'details='.urlencode($this->details).'&';
            $outlook_url .= 'body='.urlencode($this->details).'&';
            $yahoo_url .= 'desc='.urlencode($this->details).'&';
        }

        echo ButtonDropdown::widget([
            'encodeLabel' => false,
            'label' => $this->label,
            'options' => [
                'class' => 'btn-default '.$this->classes
            ],
            'dropdown' => [
                'items' => [
                    ['label' => 'Google Calendar', 'url' => $google_url, 'linkOptions' => ['target' => '_blank']],
                    ['label' => 'Outlook Calendar', 'url' => $outlook_url, 'linkOptions' => ['target' => '_blank']],
                    ['label' => 'Yahoo Calendar', 'url' => $yahoo_url, 'linkOptions' => ['target' => '_blank']],
                ],
            ],
        ]);
    }
}
