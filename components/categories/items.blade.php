@foreach ($categories as $category)
    @continue(in_array($category->getKey(), $hiddenCategories))
    @continue($hideEmptyCategory && $category->count_menus < 1)

    <li class="nav-item">
        <a
            class="nav-link font-weight-bold{{ ($selectedCategory && $category->permalink_slug == $selectedCategory->permalink_slug) ? ' active' : '' }}"
            href="{{ page_url('local/menus', ['category' => $category->permalink_slug]) }}"
        >{{ $category->name }}</a>

        @if ((!isset($displayAsFlatTree) || !$displayAsFlatTree) && count($category->children))
            <ul class="nav flex-column ml-3 my-1">
                @partial('@items', ['categories' => $category->children])
            </ul>
        @endif
    </li>
@endforeach
