<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Summary Stats -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white dark:bg-gray-900 p-6 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm">
                <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Clientes Mismatched Detectados</p>
                <p class="text-3xl font-bold mt-1 text-danger-600 dark:text-danger-400">
                    {{ count($discrepancies) }}
                </p>
            </div>
            <div class="bg-white dark:bg-gray-900 p-6 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm col-span-2">
                <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Descripción</p>
                <p class="text-sm mt-1 text-gray-600 dark:text-gray-300">
                    Este panel escanea todos los clientes buscando diferencias mayores a 0.05 BOB entre su saldo actual en la base de datos (Wallet Balance) y el saldo acumulado sumando todas sus transacciones históricas o el saldo esperado por sus consumos en sesiones de carga.
                </p>
            </div>
        </div>

        <!-- Mismatch Table -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-200 dark:border-gray-800 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Clientes con Discrepancias</h3>
                <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
                    <select 
                        wire:model.live="filterPattern" 
                        class="w-full sm:w-64 text-sm rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-950 dark:text-white focus:ring-primary-500 focus:border-primary-500 shadow-sm"
                    >
                        <option value="all">Todas las discrepancias</option>
                        <option value="recharge_unapplied">Patrón 1: Recargas no aplicadas (Dif Tx)</option>
                        <option value="sessions_unbilled">Patrón 2: Sesiones no cobradas (Cobro vs Sesión)</option>
                    </select>
                    <div class="w-full sm:w-72">
                        <input 
                            type="search" 
                            wire:model.live.debounce.300ms="search" 
                            placeholder="Buscar por nombre, email o ID..." 
                            class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-950 dark:text-white focus:ring-primary-500 focus:border-primary-500 shadow-sm"
                        />
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">
                            <th class="p-4 cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors" wire:click="sortBy('name')">
                                Cliente
                                @if($sortColumn === 'name')
                                    <span class="ml-1 text-primary-500">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </th>
                            <th class="p-4 text-right cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors" wire:click="sortBy('wallet_balance')">
                                Saldo Wallet
                                @if($sortColumn === 'wallet_balance')
                                    <span class="ml-1 text-primary-500">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </th>
                            <th class="p-4 text-right cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors" wire:click="sortBy('expected_tx_balance')">
                                Saldo Calc (Tx)
                                @if($sortColumn === 'expected_tx_balance')
                                    <span class="ml-1 text-primary-500">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </th>
                            <th class="p-4 text-right cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors" wire:click="sortBy('tx_mismatch')">
                                Diferencia (Tx)
                                @if($sortColumn === 'tx_mismatch')
                                    <span class="ml-1 text-primary-500">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </th>
                            <th class="p-4 text-right cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors" wire:click="sortBy('expected_session_balance')">
                                Saldo Calc (Sessions)
                                @if($sortColumn === 'expected_session_balance')
                                    <span class="ml-1 text-primary-500">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </th>
                            <th class="p-4 text-right cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors" wire:click="sortBy('session_mismatch')">
                                Diferencia (Sessions)
                                @if($sortColumn === 'session_mismatch')
                                    <span class="ml-1 text-primary-500">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </th>
                            <th class="p-4 text-right cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors" wire:click="sortBy('cost_discrepancy')">
                                Diferencia (Cobro vs Sesión)
                                @if($sortColumn === 'cost_discrepancy')
                                    <span class="ml-1 text-primary-500">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </th>
                            <th class="p-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800 text-sm text-gray-600 dark:text-gray-300">
                        @forelse($discrepancies as $c)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                                <td class="p-4">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $c['name'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $c['email'] }}</div>
                                    <div class="text-xs text-gray-400">ID: {{ $c['user_id'] }}</div>
                                </td>
                                <td class="p-4 text-right font-semibold text-gray-900 dark:text-white">
                                    {{ number_format($c['wallet_balance'], 2) }} BOB
                                </td>
                                <td class="p-4 text-right">
                                    {{ number_format($c['expected_tx_balance'], 2) }} BOB
                                </td>
                                <td class="p-4 text-right">
                                    @if($c['tx_mismatch'] > 0.05)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-danger-50 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400">
                                            {{ number_format($c['tx_mismatch'], 2) }} BOB
                                        </span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="p-4 text-right">
                                    {{ number_format($c['expected_session_balance'], 2) }} BOB
                                </td>
                                <td class="p-4 text-right">
                                    @if($c['session_mismatch'] > 0.05)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-warning-50 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400">
                                            {{ number_format($c['session_mismatch'], 2) }} BOB
                                        </span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="p-4 text-right">
                                    @if($c['cost_discrepancy'] > 0.05)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-info-50 text-info-700 dark:bg-info-900/30 dark:text-info-400">
                                            {{ number_format($c['cost_discrepancy'], 2) }} BOB
                                        </span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="p-4 text-center">
                                    <button 
                                        type="button" 
                                        wire:click="showTransactions({{ $c['user_id'] }}, '{{ addslashes($c['name']) }}')" 
                                        class="inline-flex items-center text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline cursor-pointer"
                                    >
                                        Ver Transacciones
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="p-8 text-center text-gray-500 dark:text-gray-400">
                                    ¡Genial! No se encontraron clientes con discrepancias de saldo.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
        <!-- Modal for Transactions -->
        <x-filament::modal id="transactions-modal" width="6xl" display-close-button="true">
            <x-slot name="heading">
                Historial de Transacciones: {{ $selectedUserName }}
            </x-slot>
            <x-slot name="subheading">
                ID Usuario: {{ $selectedUserId }}
            </x-slot>

            <div class="flex justify-end mb-4">
                <x-filament::button wire:click="exportTransactions" color="primary" icon="heroicon-m-arrow-down-tray" class="cursor-pointer">
                    Exportar a Excel (CSV)
                </x-filament::button>
            </div>

            <!-- Modal Body (Table) -->
            <div class="overflow-x-auto border border-gray-200 dark:border-gray-800 rounded-lg">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">
                            <th class="p-3 border-b">ID</th>
                            <th class="p-3 border-b">Fecha</th>
                            <th class="p-3 border-b">Tipo</th>
                            <th class="p-3 text-right border-b">Monto</th>
                            <th class="p-3 text-right border-b">Saldo</th>
                            <th class="p-3 border-b">Estado</th>
                            <th class="p-3 border-b">Método</th>
                            <th class="p-3 border-b">Descripción</th>
                            <th class="p-3 border-b">Referencia</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800 text-gray-600 dark:text-gray-300 font-normal">
                        @forelse($selectedUserTransactions as $tx)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                <td class="p-3 font-semibold">{{ $tx['id'] }}</td>
                                <td class="p-3 text-xs">{{ $tx['created_at'] }}</td>
                                <td class="p-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $tx['type'] === 'RECHARGE' ? 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-400' : 'bg-danger-50 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400' }}">
                                        {{ $tx['type'] }}
                                    </span>
                                </td>
                                <td class="p-3 text-right font-semibold {{ $tx['type'] === 'RECHARGE' ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                                    {{ number_format($tx['amount'], 2) }} BOB
                                </td>
                                <td class="p-3 text-right font-medium">
                                    {{ number_format($tx['balance_after'] ?? $tx['balance'] ?? 0, 2) }} BOB
                                </td>
                                <td class="p-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $tx['status'] === 'COMPLETED' || $tx['status'] === 'Completed' ? 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-400' : 'bg-warning-50 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400' }}">
                                        {{ $tx['status'] }}
                                    </span>
                                </td>
                                <td class="p-3 text-xs font-medium">{{ $tx['payment_method'] ?? 'APP' }}</td>
                                <td class="p-3 text-xs max-w-xs truncate" title="{{ $tx['description'] }}">{{ $tx['description'] }}</td>
                                <td class="p-3 text-xs text-gray-500">{{ $tx['reference_id'] ?? $tx['reference'] ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="p-8 text-center text-gray-500">Sin transacciones registradas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
