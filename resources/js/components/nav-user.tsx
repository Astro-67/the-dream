import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useIsMobile } from '@/hooks/use-mobile';
import { useInitials } from '@/hooks/use-initials';
import { Button } from '@/components/ui/button';

export function NavUser({ compact = false }: { compact?: boolean }) {
    const { auth } = usePage().props;
    const { state, navigation, setOpenMobile } = useSidebar();
    const isMobile = useIsMobile();
    const initials = useInitials();

    if (!auth.user) {
        return null;
    }

    return (
        <SidebarMenu className={compact ? 'w-auto' : undefined}>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        {compact ? (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-11 border border-current/50"
                                aria-label={`User menu: ${auth.user.name}`}
                            >
                                {initials(auth.user.name)}
                            </Button>
                        ) : (
                            <SidebarMenuButton
                                size="lg"
                                className="group text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent"
                                data-test="sidebar-menu-button"
                            >
                                <UserInfo user={auth.user} />
                                <ChevronsUpDown className="ml-auto size-4" />
                            </SidebarMenuButton>
                        )}
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="end"
                        side={
                            isMobile || navigation === 'menu'
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'left'
                                  : 'bottom'
                        }
                    >
                        <UserMenuContent
                            user={auth.user}
                            onNavigate={() => setOpenMobile(false)}
                        />
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
