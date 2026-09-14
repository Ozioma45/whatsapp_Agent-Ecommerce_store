<?php

namespace App\Http\Controllers;

use App\Models\Business;
use Illuminate\View\View;

class PublicStoreController extends Controller
{
    /**
     * Show a business's public store.
     *
     * The business is resolved entirely from the subdomain via route-model
     * binding on its handle (see Business::getRouteKeyName()) — a visitor
     * can never reach another business's store by manipulating an id or
     * query parameter, and an unknown handle results in a 404 automatically.
     */
    public function show(Business $business): View
    {
        $business->loadMissing('setting');

        return view('store.show', ['business' => $business]);
    }
}
