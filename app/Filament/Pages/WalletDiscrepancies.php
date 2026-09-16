<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class WalletDiscrepancies extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?string $title = 'Discrepancias de Billetera';
    protected static ?string $navigationLabel = 'Discrepancias de Billetera';
    protected static string $view = 'filament.pages.wallet-discrepancies';

    public string $search = '';
    public string $sortColumn = 'name';
    public string $sortDirection = 'asc';
    public string $filterPattern = 'all';
    public array $discrepancies = [];

    public ?int $selectedUserId = null;
    public ?string $selectedUserName = '';
    public array $selectedUserTransactions = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        $this->loadDiscrepancies();
    }

    public function updatedSearch(): void
    {
        $this->loadDiscrepancies();
    }

    public function updatedFilterPattern(): void
    {
        $this->loadDiscrepancies();
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
        $this->loadDiscrepancies();
    }

    public function showTransactions(int $userId, string $userName): void
    {
        $this->selectedUserId = $userId;
        $this->selectedUserName = $userName;
        
        $this->selectedUserTransactions = DB::table('wallet_transactions')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($tx) {
                return (array) $tx;
            })
            ->toArray();

        $this->dispatch('open-modal', id: 'transactions-modal');
    }

    public function closeModal(): void
    {
        $this->selectedUserId = null;
        $this->selectedUserTransactions = [];
        $this->selectedUserName = '';
    }

    public function exportTransactions()
    {
        if (!$this->selectedUserId) {
            return null;
        }

        $userId = $this->selectedUserId;
        $userName = str_replace(' ', '_', $this->selectedUserName);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"transacciones_usuario_{$userId}_{$userName}.csv\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($userId) {
            $file = fopen('php://output', 'w');
            
            // Add UTF-8 BOM for Excel compatibility
            fputs($file, "\xEF\xBB\xBF");

            // Header row
            fputcsv($file, [
                'ID Transacción',
                'Fecha / Hora',
                'Tipo',
                'Monto (BOB)',
                'Saldo Posterior (BOB)',
                'Estado',
                'Método de Pago',
                'Descripción',
                'Referencia',
                'ID Pago Externo',
                'Factura / Recibo',
            ]);

            DB::table('wallet_transactions')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->chunk(200, function ($transactions) use ($file) {
                    foreach ($transactions as $tx) {
                        fputcsv($file, [
                            $tx->id,
                            $tx->created_at,
                            $tx->type,
                            number_format((float)$tx->amount, 2, '.', ''),
                            number_format((float)($tx->balance_after ?? $tx->balance ?? 0), 2, '.', ''),
                            $tx->status,
                            $tx->payment_method ?? 'APP',
                            $tx->description ?? '',
                            $tx->reference_id ?? $tx->reference ?? '',
                            $tx->external_payment_id ?? '',
                            $tx->invoice_number ?? $tx->bank_receipt_number ?? '',
                        ]);
                    }
                });

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function loadDiscrepancies(): void
    {
        // Get IDs of users with physical RFID tags
        $usersWithPhysicalTags = DB::table('rfid_tags')
            ->where('is_virtual', 0)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->toArray();

        // Fetch real users only (exclude temp RFID system users)
        $usersQuery = DB::table('users')
            ->leftJoin('wallets', 'users.id', '=', 'wallets.user_id')
            ->leftJoin('companies', 'users.company_id', '=', 'companies.id')
            ->where('users.email', 'NOT LIKE', '%@evce.temp')
            ->where('users.name', 'NOT LIKE', 'Usuario RFID%');

        if ($this->filterPattern !== 'sap_risk') {
            $usersQuery->whereNull('users.company_id')
                ->whereNotIn('users.id', $usersWithPhysicalTags);
        }

        if (!empty($this->search)) {
            $searchVal = '%' . $this->search . '%';
            $usersQuery->where(function ($q) use ($searchVal) {
                $q->where('users.name', 'LIKE', $searchVal)
                  ->orWhere('users.email', 'LIKE', $searchVal)
                  ->orWhere('users.id', 'LIKE', $searchVal)
                  ->orWhere('users.billing_document', 'LIKE', $searchVal)
                  ->orWhere('companies.name', 'LIKE', $searchVal);
            });
        }

        $users = $usersQuery->select(
                'users.id as user_id',
                'users.name as client_name',
                'users.email as client_email',
                'users.billing_document',
                'users.billing_razon_social',
                'users.company_id',
                'companies.name as company_name',
                'companies.tax_id as company_tax_id',
                'wallets.balance as wallet_balance'
            )
            ->get();

        // Pre-fetch all corporate tax IDs once
        $companyTaxIds = DB::table('companies')
            ->whereNotNull('tax_id')
            ->where('tax_id', '<>', '')
            ->pluck('tax_id')
            ->map(fn($t) => preg_replace('/[^0-9]/', '', (string) $t))
            ->filter()
            ->values()
            ->toArray();

        $discrepancies = [];

        foreach ($users as $user) {
            $userId = $user->user_id;

            // 1. Recharges
            $recharges = DB::table('wallet_transactions')
                ->where('user_id', $userId)
                ->where('type', 'RECHARGE')
                ->whereIn('status', ['Completed', 'COMPLETED'])
                ->sum('amount');

            // 2. Charges (recorded in transactions)
            $charges = DB::table('wallet_transactions')
                ->where('user_id', $userId)
                ->where('type', 'CHARGE')
                ->whereIn('status', ['Completed', 'COMPLETED'])
                ->sum('amount'); // Typically negative

            // 3. Refunds/Credits
            $refunds = DB::table('wallet_transactions')
                ->where('user_id', $userId)
                ->whereIn('type', ['REFUND', 'CREDIT'])
                ->whereIn('status', ['Completed', 'COMPLETED'])
                ->sum('amount');

            // 4. Session consumption (from charging_sessions table)
            $sessionsCost = DB::table('charging_sessions')
                ->where('user_id', $userId)
                ->where('status', 'Completed')
                ->sum('total_cost');

            $walletBalance = (float) ($user->wallet_balance ?? 0);
            
            // Expected balance based strictly on transaction logs
            $expectedTxBalance = $recharges + $charges + $refunds;
            $txMismatch = abs($walletBalance - $expectedTxBalance);

            // Expected balance based on Recharges - Session Costs + Refunds
            $expectedSessionBalance = $recharges - $sessionsCost + $refunds;
            $sessionMismatch = abs($walletBalance - $expectedSessionBalance);

            // Cost discrepancy: charges vs sessions
            $costDiscrepancy = abs(abs($charges) - $sessionsCost);

            // We report a discrepancy if there's a difference larger than 0.05 BOB
            $hasTxMismatch = $txMismatch > 0.05;
            $hasCostDiscrepancy = $costDiscrepancy > 0.05;
            $hasSessionMismatch = $sessionMismatch > 0.05;

            // Real SAP Mapping Discrepancy Detection:
            $hasTransactions = ($recharges > 0 || $charges != 0 || $sessionsCost > 0);
            
            // Discrepancy: User WITHOUT company but has a corporate NIT (like SACI 1029831027) in billing_document
            $docClean = preg_replace('/[^0-9]/', '', (string) ($user->billing_document ?? ''));
            $hasCorporateNitOverlap = false;
            if (empty($user->company_id) && !empty($docClean) && strlen($docClean) >= 5) {
                foreach ($companyTaxIds as $cTaxId) {
                    if ($cTaxId !== '' && (str_contains($cTaxId, $docClean) || str_contains($docClean, $cTaxId) || str_contains($cTaxId, substr($docClean, 0, 9)))) {
                        $hasCorporateNitOverlap = true;
                        break;
                    }
                }
            }

            $hasSapRisk = $hasTransactions && ($hasCorporateNitOverlap || !empty($user->company_id));

            $matchesFilter = false;
            if ($this->filterPattern === 'all') {
                $matchesFilter = $hasTxMismatch || $hasSessionMismatch || $hasCostDiscrepancy || $hasSapRisk;
            } elseif ($this->filterPattern === 'recharge_unapplied') {
                $matchesFilter = $hasTxMismatch;
            } elseif ($this->filterPattern === 'sessions_unbilled') {
                $matchesFilter = $hasCostDiscrepancy;
            } elseif ($this->filterPattern === 'sap_risk') {
                $matchesFilter = $hasSapRisk;
            }

            if ($matchesFilter) {
                $discrepancies[] = [
                    'user_id' => $userId,
                    'name' => $user->client_name,
                    'email' => $user->client_email,
                    'billing_document' => $user->billing_document,
                    'billing_razon_social' => $user->billing_razon_social,
                    'company_id' => $user->company_id,
                    'company_name' => $user->company_name,
                    'company_tax_id' => $user->company_tax_id,
                    'has_sap_risk' => $hasSapRisk,
                    'wallet_balance' => $walletBalance,
                    'total_recharged' => $recharges,
                    'total_charged_tx' => $charges,
                    'total_refunds' => $refunds,
                    'total_sessions_cost' => $sessionsCost,
                    'expected_tx_balance' => $expectedTxBalance,
                    'tx_mismatch' => $txMismatch,
                    'expected_session_balance' => $expectedSessionBalance,
                    'session_mismatch' => $sessionMismatch,
                    'cost_discrepancy' => $costDiscrepancy,
                ];
            }
        }

        // Sort discrepancies array
        usort($discrepancies, function ($a, $b) {
            $col = $this->sortColumn;
            if ($col === 'name') {
                $valA = $a['name'] ?? '';
                $valB = $b['name'] ?? '';
            } elseif ($col === 'wallet_balance') {
                $valA = $a['wallet_balance'] ?? 0;
                $valB = $b['wallet_balance'] ?? 0;
            } elseif ($col === 'expected_tx_balance') {
                $valA = $a['expected_tx_balance'] ?? 0;
                $valB = $b['expected_tx_balance'] ?? 0;
            } elseif ($col === 'tx_mismatch') {
                $valA = $a['tx_mismatch'] ?? 0;
                $valB = $b['tx_mismatch'] ?? 0;
            } elseif ($col === 'expected_session_balance') {
                $valA = $a['expected_session_balance'] ?? 0;
                $valB = $b['expected_session_balance'] ?? 0;
            } elseif ($col === 'session_mismatch') {
                $valA = $a['session_mismatch'] ?? 0;
                $valB = $b['session_mismatch'] ?? 0;
            } elseif ($col === 'cost_discrepancy') {
                $valA = $a['cost_discrepancy'] ?? 0;
                $valB = $b['cost_discrepancy'] ?? 0;
            } else {
                $valA = $a['name'] ?? '';
                $valB = $b['name'] ?? '';
            }

            if (is_numeric($valA) && is_numeric($valB)) {
                return $this->sortDirection === 'asc' ? $valA <=> $valB : $valB <=> $valA;
            }

            return $this->sortDirection === 'asc' 
                ? strcasecmp((string)$valA, (string)$valB) 
                : strcasecmp((string)$valB, (string)$valA);
        });

        $this->discrepancies = $discrepancies;
    }
}
