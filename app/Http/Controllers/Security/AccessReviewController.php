<?php

namespace App\Http\Controllers\Security;

use App\Domain\Security\AccessReviewService;
use Illuminate\Http\Request;

final class AccessReviewController
{
    public function __construct(private AccessReviewService $service) {}

    public function campaigns()
    {
        return $this->service->campaigns();
    }

    public function create(Request $request)
    {
        return response()->json($this->service->create($request->user(), $request->all()), 201);
    }

    public function items(string $uuid)
    {
        return $this->service->items($uuid);
    }

    public function review(Request $request, string $campaign, string $item)
    {
        return $this->service->review($request->user(), $campaign, $item, $request->all());
    }
}
