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
            "Content-type" => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename=transacciones_{$userName}_{$userId}.csv",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ];

        $callback = function() use ($userId) {
            $file = fopen('php://output', 'w');
            fputs($file, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel UTF-8
            
            fputcsv($file, ['ID Transaccion', 'Fecha', 'Tipo', 'Monto (BOB)', 'Saldo Despues (BOB)', 'Estado', 'Descripcion', 'Metodo de Pago', 'Referencia']);

            $txs = DB::table('wallet_transactions')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            foreach ($txs as $tx) {
                fputcsv($file, [
                    $tx->id,
                    $tx->created_at,
                    $tx->type,
                    $tx->amount,
                    $tx->balance_after ?? $tx->balance ?? '',
                    $tx->status,
                    $tx->description,
                    $tx->payment_method,
                    $tx->reference_id ?? $tx->reference ?? '',
                ]);
            }
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

        // Fetch non-corporate users without physical RFID tags and exclude temp RFID users
        $usersQuery = DB::table('users')
            ->leftJoin('wallets', 'users.id', '=', 'wallets.user_id')
            ->whereNull('users.company_id')
            ->whereNotIn('users.id', $usersWithPhysicalTags)
            ->where('users.email', 'NOT LIKE', '%@evce.temp')
            ->where('users.name', 'NOT LIKE', 'Usuario RFID%');

        if (!empty($this->search)) {
            $searchVal = '%' . $this->search . '%';
            $usersQuery->where(function ($q) use ($searchVal) {
                $q->where('users.name', 'LIKE', $searchVal)
                  ->orWhere('users.email', 'LIKE', $searchVal)
                  ->orWhere('users.id', 'LIKE', $searchVal);
            });
        }

        $users = $usersQuery->select(
                'users.id as user_id',
                'users.name as client_name',
                'users.email as client_email',
                'wallets.balance as wallet_balance'
            )
            ->get();

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

            $matchesFilter = false;
            if ($this->filterPattern === 'all') {
                $matchesFilter = $hasTxMismatch || $hasSessionMismatch || $hasCostDiscrepancy;
            } elseif ($this->filterPattern === 'recharge_unapplied') {
                $matchesFilter = $hasTxMismatch;
            } elseif ($this->filterPattern === 'sessions_unbilled') {
                $matchesFilter = $hasCostDiscrepancy;
            }

            if ($matchesFilter) {
                $discrepancies[] = [
                    'user_id' => $userId,
                    'name' => $user->client_name,
                    'email' => $user->client_email,
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
            // Map virtual column name to array keys
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
