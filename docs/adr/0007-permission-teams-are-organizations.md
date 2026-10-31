# Permission teams are organizations; property access is a separate membership

spatie/laravel-permission in teams mode allows one `team_id` per role assignment, but Karafuu has roles at both organization level and property level. The team is the **organization**, the tenant, and holds all role assignments. Which properties a member may act in is a separate `property_memberships` table that Policies check alongside the role. Switching the team id per request was rejected as error-prone.
