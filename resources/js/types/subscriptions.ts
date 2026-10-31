export type BillingAccount = {
    type: 'bank' | 'mobile_money';
    bank?: string;
    branch?: string;
    swift?: string | null;
    provider?: string;
    account_name: string;
    account_number: string;
};
