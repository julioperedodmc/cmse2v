<div class="p-4">
    <div class="flex justify-between items-center border-b pb-4 mb-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">INVOICE</h2>
            <p class="text-sm text-gray-500">#{{ str_pad($record->id, 6, '0', STR_PAD_LEFT) }}</p>
        </div>
        <div class="text-right">
            <p class="font-bold text-primary-600">{{ config('app.name') }}</p>
            <p class="text-xs text-gray-500">{{ $record->stop_time?->format('d M Y, H:i') }}</p>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div>
            <h3 class="text-xs uppercase font-semibold text-gray-500">Bill To</h3>
            <p class="font-medium">{{ $record->user->name ?? 'Guest User' }}</p>
            <p class="text-sm text-gray-500">{{ $record->user->email ?? '-' }}</p>
        </div>
        <div class="text-right">
            <h3 class="text-xs uppercase font-semibold text-gray-500">Payment Method</h3>
            <p class="font-medium">Wallet Balance</p>
            <p class="text-sm text-gray-500">RFID: {{ $record->rfidTag->tag_code ?? 'N/A' }}</p>
        </div>
    </div>

    <table class="w-full text-sm text-left mb-6">
        <thead class="bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
            <tr>
                <th class="px-4 py-2">Description</th>
                <th class="px-4 py-2 text-right">Qty</th>
                <th class="px-4 py-2 text-right">Rate</th>
                <th class="px-4 py-2 text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @if(($record->energy_cost ?? 0) > 0)
                <tr class="border-b dark:border-gray-700">
                    <td class="px-4 py-2">Cargo por energía ({{ $record->total_energy_kwh }} kWh)</td>
                    <td class="px-4 py-2 text-right">{{ number_format($record->total_energy_kwh, 2) }}</td>
                    <td class="px-4 py-2 text-right">{{ number_format($record->rate_kwh ?? 0, 2) }}</td>
                    <td class="px-4 py-2 text-right">{{ number_format($record->energy_cost, 2) }}</td>
                </tr>
            @endif

            @if(($record->session_fee ?? 0) > 0)
                <tr class="border-b dark:border-gray-700">
                    <td class="px-4 py-2">Fee de servicio</td>
                    <td class="px-4 py-2 text-right">1</td>
                    <td class="px-4 py-2 text-right">-</td>
                    <td class="px-4 py-2 text-right">{{ number_format($record->session_fee, 2) }}</td>
                </tr>
            @endif

            @if(($record->time_fee ?? 0) > 0)
                <tr class="border-b dark:border-gray-700">
                    <td class="px-4 py-2">Multa por exceso de tiempo</td>
                    <td class="px-4 py-2 text-right">1</td>
                    <td class="px-4 py-2 text-right">-</td>
                    <td class="px-4 py-2 text-right">{{ number_format($record->time_fee, 2) }}</td>
                </tr>
            @endif

            @if(($record->discount_amount ?? 0) > 0)
                <tr class="border-b dark:border-gray-700 text-green-600 font-medium">
                    <td class="px-4 py-2">Descuento aplicado</td>
                    <td class="px-4 py-2 text-right">1</td>
                    <td class="px-4 py-2 text-right">-</td>
                    <td class="px-4 py-2 text-right">-{{ number_format($record->discount_amount, 2) }}</td>
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr class="font-bold text-lg">
                <td colspan="3" class="px-4 py-2 text-right">Total</td>
                <td class="px-4 py-2 text-right">{{ number_format($record->total_cost, 2) }} {{ $record->currency }}
                </td>
            </tr>
        </tfoot>
    </table>

    <div class="text-center text-xs text-gray-400 mt-4">
        Generated electronically. No signature required.
    </div>
</div>