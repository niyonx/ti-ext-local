<?php

namespace Igniter\Local\Components;

use Admin\Models\Addresses_model;
use Admin\Models\Customers_model;
use Exception;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Local\Facades\Location;
use Main\Facades\Auth;

class Search extends \System\Classes\BaseComponent
{
    use \Igniter\Local\Traits\SearchesNearby;
    use \Main\Traits\UsesPage;

    protected $defaultAddress;

    protected $savedAddresses;

    public function defineProperties()
    {
        return [
            'hideSearch' => [
                'label' => 'lang:igniter.local::default.label_location_search_mode',
                'type' => 'switch',
                'comment' => 'lang:igniter.local::default.help_location_search_mode',
                'validationRule' => 'required|boolean',
            ],
            'menusPage' => [
                'label' => 'Menu Page',
                'type' => 'select',
                'default' => 'local'.DIRECTORY_SEPARATOR.'menus',
                'options' => [static::class, 'getThemePageOptions'],
                'validationRule' => 'required|regex:/^[a-z0-9\-_\/]+$/i',
            ],
        ];
    }

    public function showAddressPicker()
    {
        return Auth::customer() && $this->getDefaultAddress();
    }

    public function getSavedAddresses()
    {
        if (!is_null($this->savedAddresses))
            return $this->savedAddresses;

        if (!$customer = Auth::customer())
            return null;

        return $this->savedAddresses = $customer->addresses()->get();
    }

    public function showDeliveryCoverageAlert()
    {
        if (!Location::orderTypeIsDelivery())
            return FALSE;

        if (!Location::requiresUserPosition())
            return FALSE;

        return !Location::userPosition()->hasCoordinates()
            || !Location::checkDeliveryCoverage();
    }

    public function onRun()
    {
        $this->addJs('js/local.js', 'local-module-js');

        $this->prepareVars();
    }

    public function onSetSavedAddress()
    {
        if (!$customer = Auth::customer())
            return null;

        if (!is_numeric($addressId = post('addressId')))
            throw new ApplicationException(lang('igniter.local::default.alert_address_id_required'));

        if (!$address = $customer->addresses()->find($addressId))
            throw new ApplicationException(lang('igniter.local::default.alert_address_not_found'));

        Customers_model::withoutEvents(function () use ($customer, $address) {
            $customer->address_id = $address->address_id;
            $customer->save();
        });

        $customer->reload();
        $this->controller->pageCycle();

        $this->prepareVars();

        return [
            '#local-search-container' => $this->renderPartial('@container'),
        ];
    }

    protected function prepareVars()
    {
        $this->page['menusPage'] = $this->property('menusPage');
        $this->page['hideSearch'] = $this->property('hideSearch', FALSE);
        $this->page['searchEventHandler'] = $this->getEventHandler('onSearchNearby');
        $this->page['pickerEventHandler'] = $this->getEventHandler('onSetSavedAddress');

        $this->page['searchQueryPosition'] = Location::instance()->userPosition();
        $this->page['searchDefaultAddress'] = $this->updateNearbyAreaFromSavedAddress(
            $this->getDefaultAddress()
        );
    }

    protected function updateNearbyAreaFromSavedAddress($address)
    {
        if (!$address instanceof Addresses_model)
            return $address;

        $searchQuery = format_address($address->toArray(), FALSE);
        if ($searchQuery == Location::getSession('searchQuery'))
            return $address;

        try {
            $userLocation = $this->geocodeSearchQuery($searchQuery);

            Location::searchByCoordinates($userLocation->getCoordinates())
                ->first(function ($location) use ($userLocation) {
                    if ($area = $location->searchDeliveryArea($userLocation->getCoordinates())) {
                        Location::updateNearbyArea($area);

                        return $area;
                    }
                });
        }
        catch (Exception $ex) {
        }

        return $address;
    }

    protected function getDefaultAddress()
    {
        if (!is_null($this->defaultAddress))
            return $this->defaultAddress;

        return $this->defaultAddress = optional(Auth::customer())->address
            ?? optional($this->getSavedAddresses())->first();
    }
}
