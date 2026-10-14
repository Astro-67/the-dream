export type JurisdictionProfile = 'zanzibar' | 'mainland_tanzania';

export type Currency = 'TZS' | 'USD' | 'EUR';

export type TenantOrganization = {
    id: string;
    name: string;
};

export type TenantProperty = {
    id: string;
    name: string;
    timezone: string;
    currency: Currency;
    jurisdictionProfile: JurisdictionProfile;
    jurisdictionLabel: string;
    /** The property's open business date (Y-m-d); only night audit moves it. */
    businessDate: string;
    /** A demo property: sample data only, never live. */
    demo: boolean;
    /** Present only for Zanzibar properties. */
    zraHotelCode?: string | null;
};

export type TenantPropertyOption = {
    id: string;
    name: string;
    demo: boolean;
};

/** The current tenant context, shared with every page. */
export type Tenant = {
    organization: TenantOrganization | null;
    property: TenantProperty | null;
    organizations: TenantOrganization[];
    properties: TenantPropertyOption[];
};

export type SelectOption = {
    value: string;
    label: string;
};
