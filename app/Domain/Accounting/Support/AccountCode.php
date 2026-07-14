<?php

namespace App\Domain\Accounting\Support;

use App\Domain\Accounting\Models\Account;

/**
 * The single registry of chart-of-account codes. Before this existed, every
 * service carried its own `private const AR = '1200'` — five near-identical
 * copies that a sixth feature would have copied again. Services reference a
 * case here instead; JournalPoster::post() accepts the enum directly as a
 * line's `account`, so a code string never has to be written out by hand.
 *
 * The codes themselves are seeded by ChartOfAccountsSeeder and are system rows.
 */
enum AccountCode: string
{
    // assets
    case Cash = '1000';
    case Bank = '1100';
    case ZibalClearing = '1150';
    case AccountsReceivable = '1200';
    case ChequesReceivable = '1250';
    case Inventory = '1300';
    case EmployeeAdvance = '1400';
    case SupplierAdvance = '1450';
    case FixedAssets = '1500';
    case LoansReceivable = '1600';

    // liabilities
    case AccountsPayable = '2000';
    case ChequesPayable = '2100';
    case LoansPayable = '2200';
    case PayrollPayable = '2300';
    case EmployeeCurrentAccount = '2350';
    case CustomerCredit = '2400';
    case PartnerProfitPayable = '2500';
    case PartnerCurrentAccount = '2600';

    // equity
    case Capital = '3000';
    case PartnerWithdrawal = '3100';
    case RetainedEarnings = '3200';

    // revenue
    case SalesRevenue = '4000';
    case ShippingRevenue = '4100';
    case InterestIncome = '4200';
    case OtherIncome = '4900';

    // expenses
    case Cogs = '5000';
    case ShippingCost = '5100';
    case ChannelFee = '5200';
    case GatewayFee = '5300';
    case OperatingExpense = '6000';
    case Payroll = '6100';
    case Marketing = '6200';
    case FinanceCost = '6300';
    case BankFee = '6350';
    case LatePenalty = '6370';
    case BadDebt = '6400';
    case Rounding = '9999';

    public function account(): Account
    {
        return Account::where('code', $this->value)->firstOrFail();
    }
}
