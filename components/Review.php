<?php

namespace Igniter\Local\Components;

use Admin\Traits\ValidatesForm;
use Exception;
use Igniter\Cart\Classes\OrderManager;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Local\Facades\Location;
use Igniter\Local\Models\Reviews_model;
use Igniter\Local\Models\ReviewSettings;
use Igniter\Reservation\Classes\BookingManager;
use Illuminate\Support\Facades\Redirect;
use Main\Facades\Auth;
use Main\Traits\UsesPage;

class Review extends \System\Classes\BaseComponent
{
    use ValidatesForm;
    use UsesPage;

    public function defineProperties()
    {
        return [
            'pageLimit' => [
                'label' => 'Reviews Per Page',
                'type' => 'number',
                'default' => 20,
                'validationRule' => 'required|integer',
            ],
            'sort' => [
                'label' => 'Sort reviews list by',
                'type' => 'text',
                'default' => 'created_at asc',
                'validationRule' => 'required|string',
            ],
            'reviewableType' => [
                'label' => 'Whether the review form is loaded on an order or reservation page, use by the review form',
                'type' => 'select',
                'default' => 'order',
                'options' => [
                    'order' => 'Leave order reviews',
                    'reservation' => 'Leave reservation reviews',
                ],
                'validationRule' => 'required|in:order,reservation',
            ],
            'reviewableHash' => [
                'label' => 'Review sale identifier(hash), use by the review form',
                'type' => 'text',
                'default' => '{{ :hash }}',
                'validationRule' => 'required',
            ],
            'redirectPage' => [
                'label' => 'Page to redirect to when reviews is disabled',
                'type' => 'select',
                'default' => 'local'.DIRECTORY_SEPARATOR.'menus',
                'options' => [static::class, 'getThemePageOptions'],
                'validationRule' => 'regex:/^[a-z0-9\-_\/]+$/i',
            ],
        ];
    }

    public function initialize()
    {
        $this->addCss('../formwidgets/starrating/assets/vendor/raty/jquery.raty.css', 'jquery-raty-css');
        $this->addJs('../formwidgets/starrating/assets/vendor/raty/jquery.raty.js', 'jquery-raty-js');

        $this->addCss('../formwidgets/starrating/assets/css/starrating.css', 'starrating-css');
        $this->addJs('../formwidgets/starrating/assets/js/starrating.js', 'starrating-js');
    }

    public function onRun()
    {
        $this->page['reviewDateFormat'] = lang('system::lang.moment.date_format_short');
        $this->page['reviewRatingHints'] = $this->getHints();

        $this->page['reviewList'] = $this->loadReviewList();
        $this->page['reviewable'] = $reviewable = $this->loadReviewable();
        $this->page['customerReview'] = $this->loadReview($reviewable);
    }

    public function onLeaveReview()
    {
        try {
            if (!(bool)ReviewSettings::get('allow_reviews', FALSE))
                throw new ApplicationException(lang('igniter.local::default.review.alert_review_disabled'));

            if (!$customer = Auth::customer())
                throw new ApplicationException(lang('igniter.local::default.review.alert_expired_login'));

            $reviewable = $this->getReviewable();
            if (!$reviewable || !$reviewable->isCompleted())
                throw new ApplicationException(lang('igniter.local::default.review.alert_review_status_history'));

            if ($this->checkReviewableExists($reviewable))
                throw new ApplicationException(lang('igniter.local::default.review.alert_review_duplicate'));

            $data = post();

            $rules = [
                ['rating.quality', 'lang:igniter.local::default.review.label_quality', 'required|integer'],
                ['rating.delivery', 'lang:igniter.local::default.review.label_delivery', 'required|integer'],
                ['rating.service', 'lang:igniter.local::default.review.label_service', 'required|integer'],
                ['review_text', 'lang:igniter.local::default.review.label_review', 'required|min:2|max:1028'],
            ];

            $this->validate($data, $rules);

            $model = new Reviews_model();
            $model->location_id = $reviewable->location_id;
            $model->customer_id = $customer->customer_id;
            $model->author = $customer->full_name;
            $model->sale_id = $reviewable->getKey();
            $model->sale_type = $reviewable->getMorphClass();
            $model->quality = array_get($data, 'rating.quality');
            $model->delivery = array_get($data, 'rating.delivery');
            $model->service = array_get($data, 'rating.service');
            $model->review_text = array_get($data, 'review_text');
            $model->review_status = !(bool)ReviewSettings::get('approve_reviews', FALSE) ? 1 : 0;

            $model->save();

            flash()->success(lang('igniter.local::default.review.alert_review_success'))->now();

            return Redirect::back();
        }
        catch (Exception $ex) {
            flash()->warning($ex->getMessage());

            return Redirect::back()->withInput();
        }
    }

    /**
     * @return mixed
     */
    protected function getHints()
    {
        return Reviews_model::make()->getRatingOptions();
    }

    protected function loadReviewList()
    {
        if (!$location = Location::current())
            return null;

        $list = Reviews_model::with(['customer', 'customer.address'])->listFrontEnd([
            'page' => $this->param('page'),
            'pageLimit' => $this->property('pageLimit'),
            'sort' => $this->property('sort', 'created_at asc'),
            'location' => $location->getKey(),
        ]);

        return $list;
    }

    protected function loadReviewable()
    {
        $reviewable = $this->getReviewable();

        if (!$reviewable || !$reviewable->isCompleted())
            return null;

        return $reviewable;
    }

    protected function loadReview($reviewable)
    {
        if (!$reviewable)
            return null;

        return Reviews_model::whereReviewable($reviewable)->first();
    }

    protected function getReviewable()
    {
        $reviewableHash = $this->param('hash', $this->property('reviewableHash'));

        $reviewable = null;
        if ($this->property('reviewableType') == 'reservation') {
            $reviewable = BookingManager::instance()->getReservationByHash($reviewableHash, Auth::customer());
        }
        elseif ($this->property('reviewableType') == 'order') {
            $reviewable = OrderManager::instance()->getOrderByHash($reviewableHash, Auth::customer());
        }

        return $reviewable;
    }

    protected function checkReviewableExists($reviewable)
    {
        if (!$customer = Auth::customer())
            return FALSE;

        return Reviews_model::checkReviewed($reviewable, $customer);
    }
}
