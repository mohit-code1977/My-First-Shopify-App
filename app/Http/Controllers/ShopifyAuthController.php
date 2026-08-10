<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ShopifyAuthController extends Controller
{
   public function install(Request $request)
{
    $shop = $request->query('shop');

    if (!$shop) {
        return response()->json([
            'error' => 'Missing shop parameter'
        ], 400);
    }

    $scopes = env('SHOPIFY_SCOPES');
    $apiKey = env('SHOPIFY_API_KEY');
    $redirectUri = env('SHOPIFY_APP_URL') . env('SHOPIFY_REDIRECT_URI');

    $installUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
        'client_id' => $apiKey,
        'scope' => $scopes,
        'redirect_uri' => $redirectUri,
    ]);

    return redirect($installUrl);
}

    public function callback(Request $request)
    {
        return response()->json([
            'message' => 'Callback route working',
            'data' => $request->all(),
        ]);
    }
}