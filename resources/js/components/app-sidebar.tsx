import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ClipboardCheck, FileBarChart2, LayoutGrid, Package, ShoppingCart, UsersRound, Zap } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    { title: 'داشبورد', url: '/dashboard', icon: LayoutGrid },
    { title: 'سفارش‌ها', url: '/orders', icon: ShoppingCart, roles: ['admin', 'accountant', 'warehouse'] },
    { title: 'محصولات', url: '/products', icon: Package, roles: ['admin', 'accountant', 'warehouse'] },
    { title: 'بازبینی', url: '/review', icon: ClipboardCheck, roles: ['admin', 'accountant', 'warehouse'] },
    { title: 'فرم‌های سریع', url: '/fast-forms', icon: Zap, roles: ['admin', 'accountant'] },
    { title: 'گزارش‌ها', url: '/reports', icon: FileBarChart2, roles: ['admin', 'accountant', 'partner_viewer'] },
    { title: 'کاربران', url: '/users', icon: UsersRound, roles: ['admin'] },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const roles = auth.user?.roles ?? [];
    const items = mainNavItems.filter((item) => !item.roles || item.roles.some((r) => roles.includes(r)));

    return (
        <Sidebar collapsible="icon" variant="inset" side="right">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
