@php
  // Default jika variabel tidak dipassing
  $nodes    = $nodes    ?? collect();
  $lock     = $lock     ?? false;
  $allowSet = $allowSet ?? [];
@endphp

@foreach($nodes as $n)
  @php
    $id        = (int) ($n->id ?? 0);
    $children  = ($n->children ?? collect());
    $isChecked = isset($allowSet[$id]); // kebal tipe data (string/int)
  @endphp

  <li class="mb-1">
    <div class="form-check">
      <input class="form-check-input"
             type="checkbox"
             data-menu
             id="m{{ $id }}"
             name="menu[{{ $id }}]"
             @checked($isChecked)
             @disabled($lock)>
      <label class="form-check-label" for="m{{ $id }}">
        {!! !empty($n->icon) ? "<i class='{$n->icon}'></i>" : '' !!} {{ $n->name ?? '-' }}
        @if(!empty($n->path))
          <small class="text-muted">(<code>{{ $n->path }}</code>)</small>
        @endif
      </label>
    </div>

    @if($children->isNotEmpty())
      <ul class="ms-4 list-unstyled" data-tree>
        {{-- Teruskan set permission ke node anak. --}}
        @include('settings.access.tree', [
          'nodes'    => $children,
          'lock'     => $lock,
          'allowSet' => $allowSet,
        ])
      </ul>
    @endif
  </li>
@endforeach
