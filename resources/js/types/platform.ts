export type PlatformRole = 'platform_administrator' | 'compliance_operator';

export type PlatformOrganizationOwner = {
    name: string;
    email: string;
};

export type PlatformStaffMember = {
    id: string;
    name: string;
    email: string;
    roles: PlatformRole[];
    twoFactorEnabled: boolean;
};

/** One row of the platform administrators' organization list. */
export type PlatformOrganization = {
    id: string;
    name: string;
    owners: PlatformOrganizationOwner[];
    propertyCount: number;
    createdAt: string | null;
};
