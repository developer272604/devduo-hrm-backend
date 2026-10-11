<?php
namespace App\Http\Controllers\Travel;
use App\Domain\Travel\TravelService;
use Illuminate\Http\Request;
final class TravelController {
    public function __construct(private TravelService $travel) {}
    public function index(Request $r) { return $this->travel->visible($r->user())->latest('id')->paginate(25)->through(fn ($t) => $this->travel->present($t, $r->user())); }
    public function show(Request $r, string $uuid) { return $this->travel->present($this->travel->visible($r->user())->where('uuid', $uuid)->firstOrFail(), $r->user()); }
    public function create(Request $r) {
        $d = $r->validate(['purpose' => 'required|string|max:500', 'destination' => 'required|string|max:200', 'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'timezone' => 'required|timezone', 'currency' => 'required|in:USD,EUR,GBP,INR,JPY,KWD', 'budget' => 'required|string|max:20']);
        return response()->json($this->travel->present($this->travel->create($r->user(), $d), $r->user()), 201);
    }
    public function action(Request $r, string $uuid) {
        $d = $r->validate(['action' => 'required|in:submit,approve,reject,cancel', 'reason' => 'required_unless:action,submit|string|max:1000']);
        return $this->travel->present($this->travel->action($r->user(), $uuid, $d), $r->user());
    }
    public function itinerary(Request $r, string $uuid) {
        $d = $r->validate(['version' => 'required|integer|min:0', 'legs' => 'present|array|max:20',
            'legs.*' => 'array:origin,destination,departure,arrival,departure_timezone,arrival_timezone',
            'legs.*.origin' => 'required|string|max:200', 'legs.*.destination' => 'required|string|max:200',
            'legs.*.departure' => 'required|date_format:Y-m-d\TH:i:sP', 'legs.*.arrival' => 'required|date_format:Y-m-d\TH:i:sP',
            'legs.*.departure_timezone' => 'required|timezone', 'legs.*.arrival_timezone' => 'required|timezone']);
        return $this->travel->present($this->travel->itinerary($r->user(), $uuid, $d), $r->user());
    }
}
