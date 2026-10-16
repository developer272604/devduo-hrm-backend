<?php

namespace App\Http\Controllers\Workflow;

use App\Domain\Audit\Audit;
use App\Domain\Workflow\Models\Generation;
use App\Domain\Workflow\WorkflowCompiler;
use App\Domain\Workflow\WorkflowService;
use Illuminate\Http\Request;

/** Plain-language workflow drafting. Generates a draft only; saving validates through WorkflowService and publishing stays maker-checker. */
final class GenerationController
{
    public function __construct(private WorkflowService $service, private WorkflowCompiler $compiler) {}

    public function generate(Request $r)
    {
        $this->service->manager($r->user());
        $d = $r->validate(['instruction' => 'required|string|min:8|max:3000']);
        $result = $this->compiler->compile($r->user(), $d['instruction']);
        $g = Generation::create(['created_by' => $r->user()->id, 'instruction' => $d['instruction'], 'interpreter' => $result['interpreter'], 'result' => $result,
            'steps' => count($result['spec']['steps']), 'unresolved' => count($result['questions'])]);
        Audit::record('workflow.generated', 'workflows', 'generation:'.$g->uuid, ['steps' => $g->steps, 'unresolved' => $g->unresolved, 'language' => $result['language']]);

        return response()->json(['generation_uuid' => $g->uuid] + $result, 201);
    }

    public function draft(Request $r, string $uuid)
    {
        $this->service->manager($r->user());
        $g = Generation::where('uuid', $uuid)->where('created_by', $r->user()->id)->firstOrFail();
        $d = $r->validate(['name' => 'required|string|max:150', 'spec' => 'required|array:steps,trigger']);
        abort_if($g->outcome === 'drafted', 409, 'This generation was already saved as a draft.');
        $definition = $this->service->draft($r->user(), $d, null);
        $g->update(['outcome' => 'drafted', 'definition_uuid' => $definition->uuid, 'edited_before_save' => $d['spec'] != $g->result['spec']]);

        return response()->json($definition, 201);
    }

    public function index(Request $r)
    {
        $this->service->manager($r->user());
        $mine = Generation::where('created_by', $r->user()->id);

        return ['items' => (clone $mine)->latest('id')->limit(20)->get(['uuid', 'instruction', 'steps', 'unresolved', 'outcome', 'definition_uuid', 'edited_before_save', 'created_at']),
            'stats' => ['generated' => (clone $mine)->count(), 'ready_to_save' => (clone $mine)->where('unresolved', 0)->where('steps', '>', 0)->count(), 'saved_as_draft' => (clone $mine)->where('outcome', 'drafted')->count(),
                'edited_before_save' => (clone $mine)->where('edited_before_save', true)->count()], 'interpreter' => WorkflowCompiler::INTERPRETER];
    }
}
