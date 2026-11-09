// O SDK Supabase é carregado como UMD no index.html.
// Esta abordagem disponibiliza um único ficheiro, que o Service Worker pode
// guardar integralmente para permitir a inicialização da aplicação offline.

const { createClient } = window.supabase;

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
      detectSessionInUrl: true,
    },
  },
);
