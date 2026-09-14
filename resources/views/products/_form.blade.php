@csrf
@if ($product ?? null)
    @method('PUT')
@endif

<div>
    <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
    <input id="name" type="text" name="name" value="{{ old('name', $product->name ?? '') }}" required autofocus
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
    @error('name')
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

<div>
    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
    <textarea id="description" name="description" rows="3"
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">{{ old('description', $product->description ?? '') }}</textarea>
    @error('description')
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

<div>
    <label for="price" class="block text-sm font-medium text-gray-700">Price</label>
    <input id="price" type="number" step="0.01" min="0" name="price" value="{{ old('price', $product->price ?? '') }}" required
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
    @error('price')
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

<div>
    <label for="category_id" class="block text-sm font-medium text-gray-700">Category</label>
    <select id="category_id" name="category_id"
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
        <option value="">No category</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected((int) old('category_id', $product->category_id ?? '') === $category->id)>
                {{ $category->name }}
            </option>
        @endforeach
    </select>
    @error('category_id')
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Image</label>

    @if (($product ?? null)?->image)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($product->image) }}" alt="Current image"
            class="mt-2 h-16 w-16 rounded-md object-cover">
    @endif

    <input type="file" name="image" accept="image/*" class="mt-2 block w-full text-sm text-gray-700">
    <p class="mt-1 text-xs text-gray-500">JPG, PNG or WEBP, up to 2MB.</p>
    @error('image')
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

<label class="flex items-center gap-2 text-sm text-gray-700">
    <input type="checkbox" name="is_available" value="1" class="rounded border-gray-300"
        @checked(old('is_available', $product->is_available ?? true))>
    Available for sale
</label>

<button type="submit" class="w-full rounded-md bg-gray-900 px-4 py-2 text-white hover:bg-gray-700">
    {{ ($product ?? null) ? 'Save changes' : 'Create product' }}
</button>
