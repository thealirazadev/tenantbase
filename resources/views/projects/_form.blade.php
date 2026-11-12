@csrf

<div class="field @error('name') has-error @enderror">
    <label for="name">Name</label>
    <input id="name" type="text" name="name" value="{{ old('name', $project->name ?? '') }}" maxlength="255" required autofocus>
    @error('name')
        <span class="error">{{ $message }}</span>
    @enderror
</div>

<div class="field @error('description') has-error @enderror">
    <label for="description">Description</label>
    <textarea id="description" name="description" rows="4" maxlength="2000">{{ old('description', $project->description ?? '') }}</textarea>
    <span class="help">Optional.</span>
    @error('description')
        <span class="error">{{ $message }}</span>
    @enderror
</div>
