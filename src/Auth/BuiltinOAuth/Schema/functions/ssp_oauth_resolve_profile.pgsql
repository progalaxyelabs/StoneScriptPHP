-- ssp_oauth_resolve_profile — the ONE shared name/picture refresh algorithm
-- for platforms using StoneScriptPHP's builtin (standalone) Google OAuth.
--
-- Implements a fixed conformance matrix (a login-response name/picture
-- provenance rule) EXACTLY, so every adopting platform's upsert function
-- gets identical refresh semantics instead of re-implementing (and
-- drifting on) the rule itself — this is how identical behavior across
-- platforms (and any other stack implementing the same distilled algorithm)
-- is guaranteed.
--
-- Pure function: no table access, no side effects. Callers (a platform's own
-- upsert_google_user.pgsql) read current (display_name, display_name_source,
-- photo_url) from their own users table, call this, and write back the
-- returned triple.
--
-- p_is_new         — true when inserting a brand-new identity, false on a
--                     repeat login (existing row being updated).
-- p_email          — the account email; only used for the email-local-part
--                     seed on brand-new identities when the provider gives
--                     no name.
-- p_cur_name / p_cur_source / p_cur_picture
--                  — current stored state (ignored when p_is_new = true;
--                     pass NULL/empty).
-- p_provider_name / p_provider_picture
--                  — what Google returned on THIS login (NULL/empty allowed
--                     — Google can omit either on any given login).
--
-- Rule:
--   picture: provider value if non-empty, else keep current (never null an
--            existing one).
--   name:
--     - src = 'user'            -> keep name+src (never provider-overwritten)
--     - provider_name non-empty -> use it, src='provider' (covers first
--                                   login AND a system->provider upgrade, C8)
--     - is_new identity          -> seed email-local-part, src='system'
--     - otherwise (repeat login, provider omitted name, src != 'user')
--                                 -> keep current name+src
CREATE OR REPLACE FUNCTION ssp_oauth_resolve_profile(
    p_is_new boolean,
    p_email text,
    p_cur_name text,
    p_cur_source text,
    p_cur_picture text,
    p_provider_name text,
    p_provider_picture text
)
RETURNS TABLE(out_name text, out_source text, out_picture text)
LANGUAGE plpgsql
AS $$
DECLARE
    v_provider_name text := NULLIF(TRIM(COALESCE(p_provider_name, '')), '');
    v_provider_picture text := NULLIF(TRIM(COALESCE(p_provider_picture, '')), '');
    v_cur_source text := COALESCE(p_cur_source, 'provider');
    v_name text;
    v_source text;
    v_picture text;
BEGIN
    -- picture: provider-owned, refresh on every login, never nulled.
    v_picture := COALESCE(v_provider_picture, p_cur_picture);

    -- name: source-aware refresh rule.
    IF v_cur_source = 'user' AND NOT p_is_new THEN
        v_name := p_cur_name;
        v_source := 'user';
    ELSIF v_provider_name IS NOT NULL THEN
        v_name := v_provider_name;
        v_source := 'provider';
    ELSIF p_is_new THEN
        v_name := split_part(p_email, '@', 1);
        v_source := 'system';
    ELSE
        v_name := p_cur_name;
        v_source := v_cur_source;
    END IF;

    RETURN QUERY SELECT v_name, v_source, v_picture;
END;
$$;
