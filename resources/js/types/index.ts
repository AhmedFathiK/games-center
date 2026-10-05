export interface Auth {
    user: User;
}

export interface SharedData extends Record<string, unknown> {
    name: string;
    locale: string;
    availableLocales: string[];
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    locale: 'en' | 'ar';
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}
