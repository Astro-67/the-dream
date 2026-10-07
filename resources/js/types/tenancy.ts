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
    /** Present only for Zanzibar properties. */
    zraHotelCode?: string | null;
};

export type TenantPropertyOption = {
    id: string;
    name: string;
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
