<?php

namespace App\Http\Controllers\People\Config;

use App\Domain\People\Config\CustomFieldAdmin;
use App\Domain\People\Models\CustomFieldDefinition;
use App\Domain\People\Models\CustomFieldOption;
use App\Domain\People\Models\CustomSection;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

class CustomFieldAdminController extends PeopleController
{
    public function __construct(private CustomFieldAdmin $admin) {}

    public function catalogue()
    {
        return $this->admin->catalogue();
    }

    public function sections()
    {
        return ['data' => CustomSection::query()->orderBy('sort')->orderBy('id')->get()->map(fn ($s) => $this->admin->presentSection($s))->all()];
    }

    public function storeSection(Request $r)
    {
        return response()->json($this->admin->presentSection($this->admin->createSection($r->all())), 201);
    }

    public function updateSection(Request $r, int $id)
    {
        return $this->admin->presentSection($this->admin->updateSection(CustomSection::query()->findOrFail($id), $r->all()));
    }

    public function destroySection(int $id)
    {
        $this->admin->deleteSection(CustomSection::query()->findOrFail($id));

        return response()->noContent();
    }

    public function fields(Request $r)
    {
        $q = CustomFieldDefinition::query()->with(['section', 'options'])->orderBy('section_id')->orderBy('sort')->orderBy('id');
        foreach (['entity', 'section_id', 'type'] as $filter) {
            if ($r->filled($filter)) {
                $q->where($filter, $r->input($filter));
            }
        }
        if ($r->filled('active')) {
            $q->where('active', $r->boolean('active'));
        }

        return ['data' => $q->limit(CustomFieldAdmin::MAX_DEFINITIONS)->get()->map(fn ($d) => $this->admin->present($d))->all()];
    }

    public function storeField(Request $r)
    {
        return response()->json($this->admin->present($this->admin->createField($r->all())), 201);
    }

    public function showField(int $id)
    {
        return $this->admin->present(CustomFieldDefinition::query()->findOrFail($id));
    }

    public function updateField(Request $r, int $id)
    {
        return $this->admin->present($this->admin->updateField(CustomFieldDefinition::query()->findOrFail($id), $r->all()));
    }

    public function destroyField(int $id)
    {
        $this->admin->deleteField(CustomFieldDefinition::query()->findOrFail($id));

        return response()->noContent();
    }

    public function storeOption(Request $r, int $id)
    {
        return response()->json($this->admin->presentOption($this->admin->addOption(CustomFieldDefinition::query()->findOrFail($id), $r->all())), 201);
    }

    public function updateOption(Request $r, int $id, int $optionId)
    {
        $option = CustomFieldOption::query()->where('definition_id', $id)->findOrFail($optionId);

        return $this->admin->presentOption($this->admin->updateOption($option, $r->all()));
    }

    public function destroyOption(int $id, int $optionId)
    {
        $this->admin->deleteOption(CustomFieldOption::query()->where('definition_id', $id)->findOrFail($optionId));

        return response()->noContent();
    }
}
