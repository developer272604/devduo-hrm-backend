<?php
namespace App\Http\Controllers\Travel;

use App\Domain\Travel\TravelDeskService;
use Illuminate\Http\Request;

final class TravelDeskController {
    public function __construct(private TravelDeskService $desk) {}
    public function show(Request $r, string $uuid) { return $this->desk->desk($r->user(), $uuid); }
    public function requestAdvance(Request $r, string $uuid) {
        $d = $r->validate(['amount' => 'required|string|max:20', 'purpose' => 'nullable|string|max:300']);
        return response()->json($this->desk->advanceView($this->desk->requestAdvance($r->user(), $uuid, $d), $r->user()), 201);
    }
    public function decideAdvance(Request $r, string $uuid) {
        $d = $r->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required|string|min:3|max:1000']);
        return $this->desk->advanceView($this->desk->decideAdvance($r->user(), $uuid, $d['decision'], $d['reason']), $r->user());
    }
    public function cancelAdvance(Request $r, string $uuid) { return $this->desk->advanceView($this->desk->cancelAdvance($r->user(), $uuid), $r->user()); }
    public function reference(Request $r, string $uuid) {
        $d = $r->validate(['reference_type' => 'required|in:payroll,finance', 'reference' => 'required|string|max:150']);
        return $this->desk->advanceView($this->desk->recordReference($r->user(), $uuid, $d), $r->user());
    }
    public function addBooking(Request $r, string $uuid) {
        $d = $r->validate(['kind' => 'required|in:flight,hotel,rail,car,other', 'vendor' => 'required|string|max:150', 'confirmation_reference' => 'nullable|string|max:100', 'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d', 'currency' => 'required|in:USD,EUR,GBP,INR,JPY,KWD', 'cost' => 'nullable|string|max:20']);
        return response()->json($this->desk->bookingView($this->desk->addBooking($r->user(), $uuid, $d)), 201);
    }
    public function cancelBooking(Request $r, string $uuid) { return $this->desk->bookingView($this->desk->cancelBooking($r->user(), $uuid)); }
    public function reconciliation(Request $r, string $uuid) { return $this->desk->reconciliation($r->user(), $uuid); }
    public function close(Request $r, string $uuid) {
        $d = $r->validate(['note' => 'nullable|string|max:1000']);
        return response()->json($this->desk->closeReconciliation($r->user(), $uuid, $d['note'] ?? null), 201);
    }
}
