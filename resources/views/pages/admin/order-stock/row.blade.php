<tr class="order-row" data-id="{{ $item->id ?? '' }}" data-price="{{ $item->purchase_price ?? 0 }}" data-suggested-po="{{ $item->po_qty ?? 0 }}">
  <td><input type="checkbox" name="items[]" value="{{ $item->id ?? '' }}" class="row-check"></td>
  <td data-field="code">{{ $item->product_code ?? '' }}</td>
  <td data-field="name">{{ $item->product_name ?? '' }}</td>
  <td data-field="stock">{{ $item->stock_system ?? 0 }}</td>
  <td data-field="min">{{ $item->min_stock ?? '-' }}</td>
  <td data-field="max">{{ $item->max_stock ?? '-' }}</td>
  <td data-field="price">{{ number_format($item->purchase_price ?? 0, 0, ',', '.') }}</td>
  <td class="po-cell">
    <input type="number"
           name="po_qty[{{ $item->id ?? '' }}]"
           class="form-control form-control-sm po-input"
           min="0"
           max="10000"
           value="{{ $item->po_qty ?? 0 }}">
  </td>
  <td class="row-total fw-semibold">0</td>
</tr>
