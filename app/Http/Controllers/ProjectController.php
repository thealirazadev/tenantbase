<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        $projects = Project::query()->with('creator')->orderBy('name')->paginate(25);

        return view('projects.index', ['projects' => $projects]);
    }

    public function create(): View
    {
        return view('projects.create');
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = new Project($request->validated());
        $project->created_by = $request->user()->getAuthIdentifier();
        $this->saveUnique($project);

        return redirect('/projects')->with('status', 'Project created.');
    }

    public function edit(Project $project): View
    {
        return view('projects.edit', ['project' => $project]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $project->fill($request->validated());
        $this->saveUnique($project);

        return redirect('/projects')->with('status', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);
        $project->delete();

        return redirect('/projects')->with('status', 'Project deleted.');
    }

    /** Two requests can pass validation with the same name; the unique index decides. */
    private function saveUnique(Project $project): void
    {
        try {
            $project->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => 'A project with that name already exists in this workspace.',
            ]);
        }
    }
}
