import { createClient } from "https://cdn.jsdelivr.net/npm/@supabase/supabase-js/+esm";

const SUPABASE_URL = "https://mzuxdrskeniddrtehubl.supabase.co";
const SUPABASE_KEY = "sb_publishable_aYxqb__LAbNBa-BcmRpGjA_mt1roey-";

export const supabase = createClient(
    SUPABASE_URL,
    SUPABASE_KEY,
    {
        auth: {
            storage: window.sessionStorage,
            autoRefreshToken: true,
            persistSession: true,
            detectSessionInUrl: true
        }
    }
);