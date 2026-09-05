--
-- PostgreSQL database dump
--

\restrict PNBvx4KXywzXdailIK5pcJ1Bixcc0pDwAiN4nY8699I0OMTpJUnjaWCRD8VdiQV

-- Dumped from database version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


--
-- Name: unaccent; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public;


--
-- Name: EXTENSION unaccent; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION unaccent IS 'text search dictionary that removes accents';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: admins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admins (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    api_token character varying(255) DEFAULT 'admin-token-12345'::character varying,
    country character varying(2)
);


--
-- Name: admins_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admins_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admins_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admins_id_seq OWNED BY public.admins.id;


--
-- Name: ai_balance_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_balance_log (
    id bigint NOT NULL,
    provider character varying(30) DEFAULT 'deepseek'::character varying NOT NULL,
    balance numeric(12,4) NOT NULL,
    currency character varying(8) DEFAULT 'USD'::character varying NOT NULL,
    recorded_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ai_balance_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ai_balance_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ai_balance_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ai_balance_log_id_seq OWNED BY public.ai_balance_log.id;


--
-- Name: ai_processing_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_processing_jobs (
    id bigint NOT NULL,
    news_item_id bigint,
    ai_summary text,
    ai_category character varying(100),
    main_place_text character varying(500),
    relevance_mode character varying(30) DEFAULT 'national'::character varying,
    ai_status character varying(20) DEFAULT 'pending'::character varying,
    model_used character varying(50),
    prompt_version character varying(20) DEFAULT 'v1'::character varying,
    pipeline_version character varying(20),
    tokens_in integer,
    tokens_out integer,
    estimated_cost numeric(10,6),
    error_message text,
    raw_ai_output text,
    validated_summary text,
    validated_category character varying(100),
    validated_place character varying(500),
    validation_notes text,
    processed_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone,
    is_article boolean DEFAULT true NOT NULL,
    cache_hit_tokens integer,
    cache_miss_tokens integer
);


--
-- Name: ai_processing_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ai_processing_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ai_processing_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ai_processing_jobs_id_seq OWNED BY public.ai_processing_jobs.id;


--
-- Name: api_rate_limits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_rate_limits (
    id bigint NOT NULL,
    api_key character varying(64),
    endpoint character varying(200) NOT NULL,
    window_start_minute integer NOT NULL,
    request_count integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: COLUMN api_rate_limits.window_start_minute; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.api_rate_limits.window_start_minute IS 'minute-of-hour (0-59) for sliding window';


--
-- Name: api_rate_limits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_rate_limits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_rate_limits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_rate_limits_id_seq OWNED BY public.api_rate_limits.id;


--
-- Name: api_usage_analytics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_usage_analytics (
    id bigint NOT NULL,
    api_key character varying(64),
    endpoint character varying(200) NOT NULL,
    method character varying(10) DEFAULT 'GET'::character varying NOT NULL,
    response_code integer NOT NULL,
    response_time_ms integer NOT NULL,
    items_returned integer DEFAULT 0 NOT NULL,
    caller_ip character varying(45),
    user_agent character varying(500),
    requested_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: COLUMN api_usage_analytics.endpoint; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.api_usage_analytics.endpoint IS 'e.g. /api/feed/default';


--
-- Name: COLUMN api_usage_analytics.response_code; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.api_usage_analytics.response_code IS '200, 404, 429, 500...';


--
-- Name: COLUMN api_usage_analytics.response_time_ms; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.api_usage_analytics.response_time_ms IS 'timing in ms';


--
-- Name: api_usage_analytics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_usage_analytics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_usage_analytics_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_usage_analytics_id_seq OWNED BY public.api_usage_analytics.id;


--
-- Name: area_follows; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.area_follows (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    label character varying(120) NOT NULL,
    lat numeric(10,7) NOT NULL,
    lng numeric(10,7) NOT NULL,
    radius_km numeric(5,1) DEFAULT '5'::numeric NOT NULL,
    category character varying(80),
    paused boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: area_follows_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.area_follows_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: area_follows_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.area_follows_id_seq OWNED BY public.area_follows.id;


--
-- Name: bench_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bench_items (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    title character varying(500) NOT NULL,
    expect_keep boolean DEFAULT true NOT NULL,
    expect_category character varying(60),
    expect_sub character varying(80),
    expect_place character varying(200),
    expect_nowhere boolean DEFAULT false NOT NULL,
    note text,
    confirmed_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    proposed boolean DEFAULT false NOT NULL,
    proposed_reason text,
    expect_sub_id bigint
);


--
-- Name: bench_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.bench_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bench_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.bench_items_id_seq OWNED BY public.bench_items.id;


--
-- Name: bench_results; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bench_results (
    id bigint NOT NULL,
    bench_run_id bigint NOT NULL,
    bench_item_id bigint NOT NULL,
    got_keep boolean,
    got_category character varying(60),
    got_sub character varying(80),
    got_place character varying(200),
    correct json,
    error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: bench_results_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.bench_results_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bench_results_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.bench_results_id_seq OWNED BY public.bench_results.id;


--
-- Name: bench_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bench_runs (
    id bigint NOT NULL,
    adapter character varying(40) NOT NULL,
    model character varying(80) NOT NULL,
    prompt_version character varying(20),
    items smallint DEFAULT '0'::smallint NOT NULL,
    scores json,
    note character varying(200),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: bench_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.bench_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bench_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.bench_runs_id_seq OWNED BY public.bench_runs.id;


--
-- Name: blocked_urls; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.blocked_urls (
    id bigint NOT NULL,
    url character varying(1000),
    host character varying(255) NOT NULL,
    scope character varying(10) DEFAULT 'url'::character varying NOT NULL,
    reason character varying(300),
    blocked_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: blocked_urls_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.blocked_urls_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: blocked_urls_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.blocked_urls_id_seq OWNED BY public.blocked_urls.id;


--
-- Name: boundaries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.boundaries (
    id bigint NOT NULL,
    iso3 character(3) NOT NULL,
    iso2 character(2),
    level smallint NOT NULL,
    code character varying(40) NOT NULL,
    parent_code character varying(40),
    name character varying(160) NOT NULL,
    name_key character varying(160) NOT NULL,
    geometry jsonb NOT NULL,
    min_lat numeric(9,6) NOT NULL,
    max_lat numeric(9,6) NOT NULL,
    min_lng numeric(9,6) NOT NULL,
    max_lng numeric(9,6) NOT NULL,
    vertices integer DEFAULT 0 NOT NULL,
    source character varying(40) DEFAULT 'geoboundaries'::character varying NOT NULL,
    source_release character varying(40),
    licence character varying(80),
    loaded_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: boundaries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.boundaries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: boundaries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.boundaries_id_seq OWNED BY public.boundaries.id;


--
-- Name: boundary_aliases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.boundary_aliases (
    id bigint NOT NULL,
    iso3 character(3) NOT NULL,
    level smallint NOT NULL,
    code character varying(40) NOT NULL,
    alias character varying(160) NOT NULL,
    alias_key character varying(160) NOT NULL,
    language character varying(8),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: boundary_aliases_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.boundary_aliases_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: boundary_aliases_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.boundary_aliases_id_seq OWNED BY public.boundary_aliases.id;


--
-- Name: briefing_terms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.briefing_terms (
    id bigint NOT NULL,
    term character varying(80) NOT NULL,
    expansion character varying(200) NOT NULL,
    implication text,
    kind character varying(20) DEFAULT 'term'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: briefing_terms_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.briefing_terms_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: briefing_terms_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.briefing_terms_id_seq OWNED BY public.briefing_terms.id;


--
-- Name: broadcast_groups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.broadcast_groups (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: broadcast_groups_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.broadcast_groups_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: broadcast_groups_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.broadcast_groups_id_seq OWNED BY public.broadcast_groups.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.categories (
    id smallint NOT NULL,
    name character varying(255) NOT NULL,
    weight numeric(4,1) NOT NULL,
    gps character varying(10) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    name_ms character varying(255),
    name_zh character varying(255)
);


--
-- Name: community_appeals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_appeals (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    user_id bigint NOT NULL,
    against character varying(24) NOT NULL,
    text text NOT NULL,
    status character varying(16) DEFAULT 'open'::character varying NOT NULL,
    resolved_by bigint,
    resolution character varying(400),
    resolved_at timestamp(0) without time zone,
    deadline_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: community_appeals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_appeals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_appeals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_appeals_id_seq OWNED BY public.community_appeals.id;


--
-- Name: community_comment_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_comment_reports (
    id bigint NOT NULL,
    comment_id bigint NOT NULL,
    user_id bigint,
    device_token character varying(64),
    reason character varying(32) NOT NULL,
    explanation character varying(500),
    status character varying(16) DEFAULT 'open'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: community_comment_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_comment_reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_comment_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_comment_reports_id_seq OWNED BY public.community_comment_reports.id;


--
-- Name: community_comments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_comments (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    user_id bigint NOT NULL,
    parent_id bigint,
    body text NOT NULL,
    kind character varying(16) DEFAULT 'comment'::character varying NOT NULL,
    status character varying(16) DEFAULT 'published'::character varying NOT NULL,
    moderation_note character varying(300),
    edited_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: community_comments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_comments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_comments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_comments_id_seq OWNED BY public.community_comments.id;


--
-- Name: community_corrections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_corrections (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    user_id bigint NOT NULL,
    field character varying(16) NOT NULL,
    proposed_value text NOT NULL,
    explanation character varying(1000),
    evidence_url character varying(500),
    proposer_weight numeric(5,2) DEFAULT '1'::numeric NOT NULL,
    status character varying(16) DEFAULT 'open'::character varying NOT NULL,
    resolved_by bigint,
    resolved_by_type character varying(16),
    resolution_note character varying(400),
    resolved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: community_corrections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_corrections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_corrections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_corrections_id_seq OWNED BY public.community_corrections.id;


--
-- Name: community_moderation_checks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_moderation_checks (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    trigger_type character varying(16) NOT NULL,
    provider character varying(32) NOT NULL,
    model character varying(64),
    schema_version character varying(8) NOT NULL,
    decision character varying(24) NOT NULL,
    scores_json json,
    flags_json json,
    facts_json json,
    added_claims_json json,
    public_reason character varying(400),
    response_redacted text,
    latency_ms integer,
    attempt smallint DEFAULT '1'::smallint NOT NULL,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: community_moderation_checks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_moderation_checks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_moderation_checks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_moderation_checks_id_seq OWNED BY public.community_moderation_checks.id;


--
-- Name: community_moderator_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_moderator_actions (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    news_item_id bigint,
    comment_id bigint,
    action character varying(32) NOT NULL,
    reason_code character varying(32) NOT NULL,
    reason character varying(400) NOT NULL,
    reversed boolean DEFAULT false NOT NULL,
    reversed_by bigint,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: community_moderator_actions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_moderator_actions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_moderator_actions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_moderator_actions_id_seq OWNED BY public.community_moderator_actions.id;


--
-- Name: community_notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_notifications (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    type character varying(32) NOT NULL,
    news_item_id bigint,
    comment_id bigint,
    title character varying(200) NOT NULL,
    body character varying(500),
    url character varying(300),
    dedupe_key character varying(120),
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: community_notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_notifications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_notifications_id_seq OWNED BY public.community_notifications.id;


--
-- Name: community_post_media; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_post_media (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    public_path character varying(255),
    mime_type character varying(40),
    width integer,
    height integer,
    size_bytes integer,
    sha256 character varying(64),
    perceptual_hash character varying(16),
    exif_captured_at timestamp(0) without time zone,
    exif_lat_private numeric(10,7),
    exif_lng_private numeric(10,7),
    exif_consistency character varying(16),
    exif_distance_m integer,
    duplicate_of bigint,
    duplicate_kind character varying(12),
    vision_reviewed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: community_post_media_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_post_media_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_post_media_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_post_media_id_seq OWNED BY public.community_post_media.id;


--
-- Name: community_post_meta; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_post_meta (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    original_title text NOT NULL,
    original_body text NOT NULL,
    published_title text,
    published_body text,
    trust_status character varying(24) DEFAULT 'unverified'::character varying NOT NULL,
    moderation_status character varying(24) DEFAULT 'published'::character varying NOT NULL,
    seo_eligibility character varying(16) DEFAULT 'normal'::character varying NOT NULL,
    breaking boolean DEFAULT false NOT NULL,
    newsworthiness smallint,
    quality smallint,
    safety smallint,
    location_confidence smallint,
    location_type character varying(24),
    gps_lat_private numeric(10,7),
    gps_lng_private numeric(10,7),
    gps_accuracy_m integer,
    selected_lat numeric(10,7),
    selected_lng numeric(10,7),
    pin_was_adjusted boolean DEFAULT false NOT NULL,
    pin_adjustment_m integer,
    location_source character varying(16),
    place_name character varying(200),
    address_json json,
    saw_weight numeric(8,2) DEFAULT '0'::numeric NOT NULL,
    helpful_weight numeric(8,2) DEFAULT '0'::numeric NOT NULL,
    wrong_weight numeric(8,2) DEFAULT '0'::numeric NOT NULL,
    report_weight numeric(8,2) DEFAULT '0'::numeric NOT NULL,
    report_count integer DEFAULT 0 NOT NULL,
    last_review_at timestamp(0) without time zone,
    last_materially_updated_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    removed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: community_post_meta_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_post_meta_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_post_meta_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_post_meta_id_seq OWNED BY public.community_post_meta.id;


--
-- Name: community_post_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_post_versions (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    actor_type character varying(16) NOT NULL,
    actor_id bigint,
    title text NOT NULL,
    body text NOT NULL,
    reason character varying(300),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: community_post_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_post_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_post_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_post_versions_id_seq OWNED BY public.community_post_versions.id;


--
-- Name: community_reactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_reactions (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    user_id bigint,
    device_token character varying(64),
    ip_hash character varying(64),
    type character varying(12) NOT NULL,
    weight numeric(5,2) NOT NULL,
    scoring_version character varying(8) DEFAULT '1'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: community_reactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_reactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_reactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_reactions_id_seq OWNED BY public.community_reactions.id;


--
-- Name: community_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_reports (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    user_id bigint,
    device_token character varying(64),
    ip_hash character varying(64),
    reason character varying(32) NOT NULL,
    explanation character varying(1000),
    evidence_url character varying(500),
    weight numeric(5,2) NOT NULL,
    status character varying(16) DEFAULT 'open'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: community_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_reports_id_seq OWNED BY public.community_reports.id;


--
-- Name: community_reputation_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_reputation_events (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    news_item_id bigint,
    event character varying(32) NOT NULL,
    points_delta integer DEFAULT 0 NOT NULL,
    credibility_delta integer DEFAULT 0 NOT NULL,
    rules_version character varying(8) DEFAULT '1'::character varying NOT NULL,
    note character varying(300),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: community_reputation_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_reputation_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_reputation_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_reputation_events_id_seq OWNED BY public.community_reputation_events.id;


--
-- Name: community_status_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.community_status_history (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    field character varying(24) NOT NULL,
    "from" character varying(24),
    "to" character varying(24) NOT NULL,
    actor_type character varying(16) NOT NULL,
    actor_id bigint,
    reason character varying(400),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: community_status_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.community_status_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: community_status_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.community_status_history_id_seq OWNED BY public.community_status_history.id;


--
-- Name: corrections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.corrections (
    id bigint NOT NULL,
    news_item_id bigint,
    title character varying(500),
    field character varying(20) NOT NULL,
    ai_answer text,
    correct_answer text,
    reason text,
    prompt_version character varying(20),
    corrected_by bigint,
    used_in_bench boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: corrections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.corrections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: corrections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.corrections_id_seq OWNED BY public.corrections.id;


--
-- Name: dedupe_verdicts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dedupe_verdicts (
    id bigint NOT NULL,
    story_a bigint NOT NULL,
    story_b bigint NOT NULL,
    same boolean NOT NULL,
    shared_tokens smallint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dedupe_verdicts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dedupe_verdicts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dedupe_verdicts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dedupe_verdicts_id_seq OWNED BY public.dedupe_verdicts.id;


--
-- Name: extraction_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.extraction_jobs (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    extracted_title character varying(500),
    extracted_summary text,
    extracted_text text,
    extraction_status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    extraction_method character varying(50),
    extracted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    original_length integer,
    body_opening character varying(300),
    CONSTRAINT extraction_jobs_extraction_status_check CHECK (((extraction_status)::text = ANY ((ARRAY['pending'::character varying, 'success'::character varying, 'fallback_used'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: extraction_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.extraction_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: extraction_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.extraction_jobs_id_seq OWNED BY public.extraction_jobs.id;


--
-- Name: failed_ingestions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_ingestions (
    id bigint NOT NULL,
    source character varying(255) NOT NULL,
    url character varying(2048),
    title character varying(500),
    raw_payload json NOT NULL,
    failure_reason text NOT NULL,
    retry_count integer DEFAULT 0 NOT NULL,
    failed_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: failed_ingestions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_ingestions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_ingestions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_ingestions_id_seq OWNED BY public.failed_ingestions.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: feed_contents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.feed_contents (
    id bigint NOT NULL,
    url character varying(1000) NOT NULL,
    text text NOT NULL,
    source character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    url_hash character varying(40) NOT NULL
);


--
-- Name: feed_contents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.feed_contents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: feed_contents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.feed_contents_id_seq OWNED BY public.feed_contents.id;


--
-- Name: feed_ready_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.feed_ready_items (
    id bigint NOT NULL,
    news_item_id bigint,
    title character varying(500) NOT NULL,
    summary text,
    source character varying(255),
    url character varying(1000) NOT NULL,
    published_at timestamp(0) without time zone,
    primary_category character varying(255),
    secondary_category character varying(255),
    location_label character varying(500),
    lat numeric(10,7),
    lng numeric(10,7),
    precision_type character varying(255),
    distance_km numeric(8,2),
    relevance_mode character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    canonical_place_name character varying(500),
    geo_confidence_score numeric(5,4),
    coverage_type character varying(30),
    sort_timestamp timestamp without time zone,
    is_article boolean DEFAULT true,
    sub_category character varying(255),
    origin character varying(20) DEFAULT 'scraper'::character varying NOT NULL,
    image_path character varying(255),
    is_primary_location boolean DEFAULT true NOT NULL,
    sub_category_id bigint,
    event_start date,
    event_end date,
    event_open_ended boolean DEFAULT false NOT NULL,
    geo_country_code character(3),
    geo_state_code character varying(40),
    geo_city_code character varying(40),
    published_precision character varying(8)
);


--
-- Name: feed_ready_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.feed_ready_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: feed_ready_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.feed_ready_items_id_seq OWNED BY public.feed_ready_items.id;


--
-- Name: gazetteer; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gazetteer (
    id bigint NOT NULL,
    source character varying(16) NOT NULL,
    source_id character varying(80) NOT NULL,
    name character varying(250) NOT NULL,
    name_key character varying(250) NOT NULL,
    alt_names text,
    kind character varying(64),
    category character varying(160),
    lat double precision NOT NULL,
    lng double precision NOT NULL,
    country character(3),
    state_code character varying(64),
    admin_text character varying(250),
    population integer,
    raw jsonb,
    stamped_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    dup_of bigint
);


--
-- Name: gazetteer_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.gazetteer_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: gazetteer_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.gazetteer_id_seq OWNED BY public.gazetteer.id;


--
-- Name: geocode_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.geocode_cache (
    id bigint NOT NULL,
    place_key character varying(255) NOT NULL,
    place_text character varying(255) NOT NULL,
    lat numeric(10,7),
    lng numeric(10,7),
    label character varying(255),
    confidence numeric(8,4),
    provider character varying(255) DEFAULT 'nominatim'::character varying NOT NULL,
    status character varying(255) DEFAULT 'success'::character varying NOT NULL,
    hits integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    state character varying(120),
    country character varying(120)
);


--
-- Name: geocode_cache_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.geocode_cache_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: geocode_cache_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.geocode_cache_id_seq OWNED BY public.geocode_cache.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: location_aliases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.location_aliases (
    id bigint NOT NULL,
    alias_text character varying(200) NOT NULL,
    canonical_name character varying(500) NOT NULL,
    alias_type character varying(30) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: COLUMN location_aliases.alias_text; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.location_aliases.alias_text IS 'e.g. PJ, KL, Klang Valley';


--
-- Name: COLUMN location_aliases.canonical_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.location_aliases.canonical_name IS 'e.g. Petaling Jaya, Kuala Lumpur';


--
-- Name: COLUMN location_aliases.alias_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.location_aliases.alias_type IS 'city, district, region, area';


--
-- Name: location_aliases_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.location_aliases_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: location_aliases_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.location_aliases_id_seq OWNED BY public.location_aliases.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: news_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.news_items (
    id bigint NOT NULL,
    title character varying(255) NOT NULL,
    summary text,
    source character varying(255),
    url character varying(1000) NOT NULL,
    published_at timestamp(0) without time zone,
    primary_category character varying(255),
    secondary_category character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    main_place_text character varying(255),
    lat numeric(10,7),
    lng numeric(10,7),
    precision_type character varying(255),
    geo_confidence numeric(5,2),
    relevance_mode character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    topic_extraction_status character varying(255),
    topic_extracted_at timestamp(0) without time zone,
    topic_extraction_model character varying(255),
    location_label character varying(255),
    ai_summary text,
    ai_category character varying(255),
    ai_status character varying(50) DEFAULT 'pending'::character varying,
    ai_processed_at timestamp without time zone,
    ai_model character varying(50),
    ai_prompt_version character varying(20),
    ai_tokens_in integer,
    ai_tokens_out integer,
    ai_estimated_cost numeric(10,6),
    canonical_place_name character varying(500),
    alias_match_status character varying(20),
    geocode_status character varying(20),
    geocode_confidence numeric(5,2),
    is_article boolean DEFAULT true NOT NULL,
    alias_match_type character varying(30),
    alias_matched_at timestamp(0) without time zone,
    latitude numeric(10,7),
    longitude numeric(10,7),
    geocode_provider character varying(50),
    geocoded_at timestamp(0) without time zone,
    coverage_type character varying(30),
    geo_confidence_score numeric(5,4),
    coverage_status character varying(30),
    geo_processed_at timestamp(0) without time zone,
    enrichment_failure_reason character varying(255),
    enrichment_failure_count smallint DEFAULT '0'::smallint NOT NULL,
    sub_category character varying(255),
    source_language character varying(8),
    translated_at timestamp(0) without time zone,
    discarded boolean DEFAULT false NOT NULL,
    error_code character varying(40),
    meta_confidence numeric(3,2),
    gps_flag boolean DEFAULT false NOT NULL,
    url_used boolean DEFAULT false NOT NULL,
    ambiguous boolean DEFAULT false NOT NULL,
    classification json,
    spec_version character varying(12),
    malaysia_relevant boolean,
    relevance_reason character varying(120),
    origin character varying(20) DEFAULT 'scraper'::character varying NOT NULL,
    contributor_id bigint,
    section character varying(20),
    body text,
    image_path character varying(255),
    review_status character varying(20),
    review_reason character varying(300),
    approved_by bigint,
    approved_at timestamp(0) without time zone,
    is_multi_point boolean DEFAULT false NOT NULL,
    outlet_scale character varying(20),
    outlet_note text,
    place_roles jsonb,
    duplicate_of bigint,
    duplicate_reason character varying(40),
    sub_category_id bigint,
    event_start date,
    event_end date,
    event_open_ended boolean DEFAULT false NOT NULL,
    geo_country_code character(3),
    geo_state_code character varying(40),
    geo_claim_country character(3),
    geo_city_code character varying(40),
    published_precision character varying(8),
    geo_claim_state character varying(40),
    geo_note text
);


--
-- Name: news_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.news_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: news_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.news_items_id_seq OWNED BY public.news_items.id;


--
-- Name: news_translations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.news_translations (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    locale character varying(8) NOT NULL,
    title character varying(600) NOT NULL,
    summary text,
    model character varying(60),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: news_translations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.news_translations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: news_translations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.news_translations_id_seq OWNED BY public.news_translations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: place_reviews; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.place_reviews (
    id bigint NOT NULL,
    place_text character varying(300) NOT NULL,
    place_key character varying(300) NOT NULL,
    news_item_id bigint,
    story_title character varying(300),
    story_url character varying(600),
    source character varying(120),
    attempts jsonb,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    lat numeric(10,7),
    lng numeric(10,7),
    resolved_label character varying(300),
    note text,
    resolved_by character varying(120),
    resolved_at timestamp(0) without time zone,
    story_count integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    suggestions jsonb,
    placed_at jsonb
);


--
-- Name: place_reviews_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.place_reviews_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: place_reviews_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.place_reviews_id_seq OWNED BY public.place_reviews.id;


--
-- Name: playbook_revisions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.playbook_revisions (
    id bigint NOT NULL,
    playbook_section_id bigint NOT NULL,
    body text NOT NULL,
    note character varying(200),
    edited_by bigint,
    created_at timestamp(0) without time zone
);


--
-- Name: playbook_revisions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.playbook_revisions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: playbook_revisions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.playbook_revisions_id_seq OWNED BY public.playbook_revisions.id;


--
-- Name: playbook_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.playbook_sections (
    id bigint NOT NULL,
    key character varying(40) NOT NULL,
    title character varying(120) NOT NULL,
    body text NOT NULL,
    why text,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    country character varying(2)
);


--
-- Name: playbook_sections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.playbook_sections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: playbook_sections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.playbook_sections_id_seq OWNED BY public.playbook_sections.id;


--
-- Name: raw_ingest; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.raw_ingest (
    id bigint NOT NULL,
    source character varying(255) NOT NULL,
    raw_json_payload json NOT NULL,
    received_at timestamp(0) without time zone NOT NULL,
    processing_status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    news_item_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT raw_ingest_processing_status_check CHECK (((processing_status)::text = ANY ((ARRAY['pending'::character varying, 'processed'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: raw_ingest_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.raw_ingest_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: raw_ingest_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.raw_ingest_id_seq OWNED BY public.raw_ingest.id;


--
-- Name: removals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.removals (
    id bigint NOT NULL,
    news_item_id bigint,
    title character varying(500) NOT NULL,
    origin character varying(20) DEFAULT 'scraper'::character varying NOT NULL,
    source character varying(255),
    primary_category character varying(100),
    reason text NOT NULL,
    removed_by bigint,
    reviewed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: removals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.removals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: removals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.removals_id_seq OWNED BY public.removals.id;


--
-- Name: reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reports (
    id bigint NOT NULL,
    news_item_id bigint,
    reason character varying(255) NOT NULL,
    note text,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    ip_address character varying(45),
    user_agent character varying(512),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT reports_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'reviewed'::character varying, 'resolved'::character varying, 'dismissed'::character varying])::text[])))
);


--
-- Name: reports_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.reports_id_seq OWNED BY public.reports.id;


--
-- Name: review_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.review_rules (
    id bigint NOT NULL,
    rule text NOT NULL,
    applies_to character varying(20) DEFAULT 'both'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    country character varying(2)
);


--
-- Name: review_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.review_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: review_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.review_rules_id_seq OWNED BY public.review_rules.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: source_daily_stats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.source_daily_stats (
    id bigint NOT NULL,
    source_id bigint NOT NULL,
    day date NOT NULL,
    items_new integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: source_daily_stats_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.source_daily_stats_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: source_daily_stats_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.source_daily_stats_id_seq OWNED BY public.source_daily_stats.id;


--
-- Name: sources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sources (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    base_url character varying(255),
    rss_url character varying(255),
    language character varying(20) DEFAULT 'English'::character varying NOT NULL,
    source_type character varying(40) DEFAULT 'mainstream'::character varying NOT NULL,
    direct_rss_supported boolean DEFAULT true NOT NULL,
    google_news_supported boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    priority_tier character varying(20) DEFAULT 'primary'::character varying NOT NULL,
    publisher_id bigint,
    last_fetched_at timestamp(0) without time zone,
    last_status character varying(40),
    last_item_count integer DEFAULT 0 NOT NULL,
    consecutive_failures integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    discovery_status character varying(20) DEFAULT 'manual'::character varying NOT NULL,
    discovered_from character varying(255),
    parent_source_id bigint,
    section character varying(60),
    sightings integer DEFAULT 0 NOT NULL,
    last_probed_at timestamp(0) without time zone,
    probe_notes text,
    fetch_recipe json,
    items_contributed bigint DEFAULT '0'::bigint NOT NULL,
    items_duplicate bigint DEFAULT '0'::bigint NOT NULL,
    source_kind character varying(12) DEFAULT 'rss'::character varying NOT NULL,
    index_url character varying(255),
    link_pattern character varying(255),
    expect_note text,
    extract_note text,
    tech_note text,
    fetch_interval_minutes smallint,
    fetch_at_hour smallint,
    notes_updated_at timestamp(0) without time zone,
    country character varying(2) DEFAULT 'MY'::character varying,
    extraction_strategy character varying(20),
    robots_policy character varying(20),
    robots_note text,
    robots_checked_at timestamp(0) without time zone,
    licence_contact character varying(190),
    routes json,
    best_route character varying(20),
    sections_published smallint,
    sections_reachable smallint,
    coverage_pct smallint,
    constraint_note text,
    workaround_note text,
    audited_at timestamp(0) without time zone,
    is_event_source boolean DEFAULT false NOT NULL,
    event_open_days smallint DEFAULT '30'::smallint NOT NULL
);


--
-- Name: sources_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sources_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sources_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sources_id_seq OWNED BY public.sources.id;


--
-- Name: story_locations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.story_locations (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    label character varying(200) NOT NULL,
    lat numeric(10,7),
    lng numeric(10,7),
    added_by character varying(20) DEFAULT 'ai'::character varying NOT NULL,
    geocode_status character varying(20),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    ai_confident boolean
);


--
-- Name: story_locations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.story_locations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: story_locations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.story_locations_id_seq OWNED BY public.story_locations.id;


--
-- Name: subcategories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subcategories (
    id integer NOT NULL,
    primary_category character varying(100) NOT NULL,
    sub_category character varying(200) NOT NULL,
    weight numeric(4,1) NOT NULL,
    gps character varying(3) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    sub_category_ms character varying(255),
    sub_category_zh character varying(255),
    created_by character varying(20) DEFAULT 'admin'::character varying NOT NULL
);


--
-- Name: subcategories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subcategories_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subcategories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subcategories_id_seq OWNED BY public.subcategories.id;


--
-- Name: subscribers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscribers (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    phone character varying(20) NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    group_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    preferences jsonb,
    preferred_categories jsonb,
    alert_radius_km double precision,
    location_lat double precision,
    location_lng double precision,
    notify_email boolean DEFAULT true NOT NULL,
    notify_webhook boolean DEFAULT false NOT NULL,
    notify_in_app boolean DEFAULT true NOT NULL,
    max_notifications_per_hour integer DEFAULT 10 NOT NULL,
    join_date timestamp(0) without time zone,
    CONSTRAINT subscribers_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying, 'blocked'::character varying])::text[])))
);


--
-- Name: subscribers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscribers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscribers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscribers_id_seq OWNED BY public.subscribers.id;


--
-- Name: topic_entities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.topic_entities (
    id bigint NOT NULL,
    news_item_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    normalized_name text,
    confidence character varying(255),
    topic_cluster character varying(255),
    topic_score integer,
    first_position integer,
    last_position integer,
    occurrence_count integer DEFAULT 1 NOT NULL,
    extraction_model character varying(255),
    extracted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: topic_entities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.topic_entities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: topic_entities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.topic_entities_id_seq OWNED BY public.topic_entities.id;


--
-- Name: topic_follows; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.topic_follows (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    category character varying(80) NOT NULL,
    paused boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: topic_follows_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.topic_follows_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: topic_follows_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.topic_follows_id_seq OWNED BY public.topic_follows.id;


--
-- Name: user_blocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_blocks (
    id bigint NOT NULL,
    blocker_id bigint NOT NULL,
    blocked_id bigint NOT NULL,
    kind character varying(8) DEFAULT 'block'::character varying NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: user_blocks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_blocks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_blocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_blocks_id_seq OWNED BY public.user_blocks.id;


--
-- Name: user_community_badges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_community_badges (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    badge character varying(40) NOT NULL,
    detail character varying(120),
    awarded_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    revoked_at timestamp(0) without time zone
);


--
-- Name: user_community_badges_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_community_badges_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_community_badges_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_community_badges_id_seq OWNED BY public.user_community_badges.id;


--
-- Name: user_follows; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_follows (
    id bigint NOT NULL,
    follower_id bigint NOT NULL,
    followed_id bigint NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: user_follows_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_follows_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_follows_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_follows_id_seq OWNED BY public.user_follows.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_code character varying(255),
    mobile character varying(255),
    wa_group character varying(255),
    interest_sub_cat character varying(255),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    location_name character varying(255),
    join_date timestamp(0) without time zone,
    trusted_at timestamp(0) without time zone,
    trusted_by bigint,
    username character varying(40),
    display_name character varying(120),
    bio character varying(500),
    area character varying(120),
    avatar_path character varying(255),
    points integer DEFAULT 0 NOT NULL,
    credibility integer DEFAULT 50 NOT NULL,
    notify_mode character varying(12) DEFAULT 'daily'::character varying NOT NULL,
    community_role character varying(16),
    role_granted_at timestamp(0) without time zone,
    role_granted_by bigint,
    CONSTRAINT users_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying, 'pending'::character varying])::text[])))
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: admins id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins ALTER COLUMN id SET DEFAULT nextval('public.admins_id_seq'::regclass);


--
-- Name: ai_balance_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_balance_log ALTER COLUMN id SET DEFAULT nextval('public.ai_balance_log_id_seq'::regclass);


--
-- Name: ai_processing_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_processing_jobs ALTER COLUMN id SET DEFAULT nextval('public.ai_processing_jobs_id_seq'::regclass);


--
-- Name: api_rate_limits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_rate_limits ALTER COLUMN id SET DEFAULT nextval('public.api_rate_limits_id_seq'::regclass);


--
-- Name: api_usage_analytics id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_usage_analytics ALTER COLUMN id SET DEFAULT nextval('public.api_usage_analytics_id_seq'::regclass);


--
-- Name: area_follows id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.area_follows ALTER COLUMN id SET DEFAULT nextval('public.area_follows_id_seq'::regclass);


--
-- Name: bench_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bench_items ALTER COLUMN id SET DEFAULT nextval('public.bench_items_id_seq'::regclass);


--
-- Name: bench_results id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bench_results ALTER COLUMN id SET DEFAULT nextval('public.bench_results_id_seq'::regclass);


--
-- Name: bench_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bench_runs ALTER COLUMN id SET DEFAULT nextval('public.bench_runs_id_seq'::regclass);


--
-- Name: blocked_urls id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blocked_urls ALTER COLUMN id SET DEFAULT nextval('public.blocked_urls_id_seq'::regclass);


--
-- Name: boundaries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.boundaries ALTER COLUMN id SET DEFAULT nextval('public.boundaries_id_seq'::regclass);


--
-- Name: boundary_aliases id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.boundary_aliases ALTER COLUMN id SET DEFAULT nextval('public.boundary_aliases_id_seq'::regclass);


--
-- Name: briefing_terms id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.briefing_terms ALTER COLUMN id SET DEFAULT nextval('public.briefing_terms_id_seq'::regclass);


--
-- Name: broadcast_groups id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_groups ALTER COLUMN id SET DEFAULT nextval('public.broadcast_groups_id_seq'::regclass);


--
-- Name: community_appeals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_appeals ALTER COLUMN id SET DEFAULT nextval('public.community_appeals_id_seq'::regclass);


--
-- Name: community_comment_reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_comment_reports ALTER COLUMN id SET DEFAULT nextval('public.community_comment_reports_id_seq'::regclass);


--
-- Name: community_comments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_comments ALTER COLUMN id SET DEFAULT nextval('public.community_comments_id_seq'::regclass);


--
-- Name: community_corrections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_corrections ALTER COLUMN id SET DEFAULT nextval('public.community_corrections_id_seq'::regclass);


--
-- Name: community_moderation_checks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_moderation_checks ALTER COLUMN id SET DEFAULT nextval('public.community_moderation_checks_id_seq'::regclass);


--
-- Name: community_moderator_actions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_moderator_actions ALTER COLUMN id SET DEFAULT nextval('public.community_moderator_actions_id_seq'::regclass);


--
-- Name: community_notifications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_notifications ALTER COLUMN id SET DEFAULT nextval('public.community_notifications_id_seq'::regclass);


--
-- Name: community_post_media id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_post_media ALTER COLUMN id SET DEFAULT nextval('public.community_post_media_id_seq'::regclass);


--
-- Name: community_post_meta id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_post_meta ALTER COLUMN id SET DEFAULT nextval('public.community_post_meta_id_seq'::regclass);


--
-- Name: community_post_versions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_post_versions ALTER COLUMN id SET DEFAULT nextval('public.community_post_versions_id_seq'::regclass);


--
-- Name: community_reactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_reactions ALTER COLUMN id SET DEFAULT nextval('public.community_reactions_id_seq'::regclass);


--
-- Name: community_reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_reports ALTER COLUMN id SET DEFAULT nextval('public.community_reports_id_seq'::regclass);


--
-- Name: community_reputation_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_reputation_events ALTER COLUMN id SET DEFAULT nextval('public.community_reputation_events_id_seq'::regclass);


--
-- Name: community_status_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_status_history ALTER COLUMN id SET DEFAULT nextval('public.community_status_history_id_seq'::regclass);


--
-- Name: corrections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.corrections ALTER COLUMN id SET DEFAULT nextval('public.corrections_id_seq'::regclass);


--
-- Name: dedupe_verdicts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dedupe_verdicts ALTER COLUMN id SET DEFAULT nextval('public.dedupe_verdicts_id_seq'::regclass);


--
-- Name: extraction_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.extraction_jobs ALTER COLUMN id SET DEFAULT nextval('public.extraction_jobs_id_seq'::regclass);


--
-- Name: failed_ingestions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_ingestions ALTER COLUMN id SET DEFAULT nextval('public.failed_ingestions_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: feed_contents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feed_contents ALTER COLUMN id SET DEFAULT nextval('public.feed_contents_id_seq'::regclass);


--
-- Name: feed_ready_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feed_ready_items ALTER COLUMN id SET DEFAULT nextval('public.feed_ready_items_id_seq'::regclass);


--
-- Name: gazetteer id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gazetteer ALTER COLUMN id SET DEFAULT nextval('public.gazetteer_id_seq'::regclass);


--
-- Name: geocode_cache id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geocode_cache ALTER COLUMN id SET DEFAULT nextval('public.geocode_cache_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: location_aliases id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.location_aliases ALTER COLUMN id SET DEFAULT nextval('public.location_aliases_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: news_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.news_items ALTER COLUMN id SET DEFAULT nextval('public.news_items_id_seq'::regclass);


--
-- Name: news_translations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.news_translations ALTER COLUMN id SET DEFAULT nextval('public.news_translations_id_seq'::regclass);


--
-- Name: place_reviews id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.place_reviews ALTER COLUMN id SET DEFAULT nextval('public.place_reviews_id_seq'::regclass);


--
-- Name: playbook_revisions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.playbook_revisions ALTER COLUMN id SET DEFAULT nextval('public.playbook_revisions_id_seq'::regclass);


--
-- Name: playbook_sections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.playbook_sections ALTER COLUMN id SET DEFAULT nextval('public.playbook_sections_id_seq'::regclass);


--
-- Name: raw_ingest id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_ingest ALTER COLUMN id SET DEFAULT nextval('public.raw_ingest_id_seq'::regclass);


--
-- Name: removals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.removals ALTER COLUMN id SET DEFAULT nextval('public.removals_id_seq'::regclass);


--
-- Name: reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reports ALTER COLUMN id SET DEFAULT nextval('public.reports_id_seq'::regclass);


--
-- Name: review_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.review_rules ALTER COLUMN id SET DEFAULT nextval('public.review_rules_id_seq'::regclass);


--
-- Name: source_daily_stats id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.source_daily_stats ALTER COLUMN id SET DEFAULT nextval('public.source_daily_stats_id_seq'::regclass);


--
-- Name: sources id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sources ALTER COLUMN id SET DEFAULT nextval('public.sources_id_seq'::regclass);


--
-- Name: story_locations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.story_locations ALTER COLUMN id SET DEFAULT nextval('public.story_locations_id_seq'::regclass);


--
-- Name: subcategories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcategories ALTER COLUMN id SET DEFAULT nextval('public.subcategories_id_seq'::regclass);


--
-- Name: subscribers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscribers ALTER COLUMN id SET DEFAULT nextval('public.subscribers_id_seq'::regclass);


--
-- Name: topic_entities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.topic_entities ALTER COLUMN id SET DEFAULT nextval('public.topic_entities_id_seq'::regclass);


--
-- Name: topic_follows id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.topic_follows ALTER COLUMN id SET DEFAULT nextval('public.topic_follows_id_seq'::regclass);


--
-- Name: user_blocks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_blocks ALTER COLUMN id SET DEFAULT nextval('public.user_blocks_id_seq'::regclass);


--
-- Name: user_community_badges id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_community_badges ALTER COLUMN id SET DEFAULT nextval('public.user_community_badges_id_seq'::regclass);


--
-- Name: user_follows id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_follows ALTER COLUMN id SET DEFAULT nextval('public.user_follows_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: admins admins_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_email_unique UNIQUE (email);


--
-- Name: admins admins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_pkey PRIMARY KEY (id);


--
-- Name: ai_balance_log ai_balance_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_balance_log
    ADD CONSTRAINT ai_balance_log_pkey PRIMARY KEY (id);


--
-- Name: ai_processing_jobs ai_processing_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_processing_jobs
    ADD CONSTRAINT ai_processing_jobs_pkey PRIMARY KEY (id);


--
-- Name: api_rate_limits api_rate_limits_api_key_endpoint_window_start_minute_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_rate_limits
    ADD CONSTRAINT api_rate_limits_api_key_endpoint_window_start_minute_unique UNIQUE (api_key, endpoint, window_start_minute);


--
-- Name: api_rate_limits api_rate_limits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_rate_limits
    ADD CONSTRAINT api_rate_limits_pkey PRIMARY KEY (id);


--
-- Name: api_usage_analytics api_usage_analytics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_usage_analytics
    ADD CONSTRAINT api_usage_analytics_pkey PRIMARY KEY (id);


--
-- Name: area_follows area_follows_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.area_follows
    ADD CONSTRAINT area_follows_pkey PRIMARY KEY (id);


--
-- Name: bench_items bench_items_news_item_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bench_items
    ADD CONSTRAINT bench_items_news_item_id_unique UNIQUE (news_item_id);


--
-- Name: bench_items bench_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bench_items
    ADD CONSTRAINT bench_items_pkey PRIMARY KEY (id);


--
-- Name: bench_results bench_results_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bench_results
    ADD CONSTRAINT bench_results_pkey PRIMARY KEY (id);


--
-- Name: bench_runs bench_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bench_runs
    ADD CONSTRAINT bench_runs_pkey PRIMARY KEY (id);


--
-- Name: blocked_urls blocked_urls_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blocked_urls
    ADD CONSTRAINT blocked_urls_pkey PRIMARY KEY (id);


--
-- Name: boundaries boundaries_iso3_level_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.boundaries
    ADD CONSTRAINT boundaries_iso3_level_code_unique UNIQUE (iso3, level, code);


--
-- Name: boundaries boundaries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.boundaries
    ADD CONSTRAINT boundaries_pkey PRIMARY KEY (id);


--
-- Name: boundary_aliases boundary_aliases_iso3_level_alias_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.boundary_aliases
    ADD CONSTRAINT boundary_aliases_iso3_level_alias_key_unique UNIQUE (iso3, level, alias_key);


--
-- Name: boundary_aliases boundary_aliases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.boundary_aliases
    ADD CONSTRAINT boundary_aliases_pkey PRIMARY KEY (id);


--
-- Name: briefing_terms briefing_terms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.briefing_terms
    ADD CONSTRAINT briefing_terms_pkey PRIMARY KEY (id);


--
-- Name: briefing_terms briefing_terms_term_kind_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.briefing_terms
    ADD CONSTRAINT briefing_terms_term_kind_unique UNIQUE (term, kind);


--
-- Name: broadcast_groups broadcast_groups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_groups
    ADD CONSTRAINT broadcast_groups_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: categories categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (id);


--
-- Name: community_appeals community_appeals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_appeals
    ADD CONSTRAINT community_appeals_pkey PRIMARY KEY (id);


--
-- Name: community_comment_reports community_comment_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_comment_reports
    ADD CONSTRAINT community_comment_reports_pkey PRIMARY KEY (id);


--
-- Name: community_comments community_comments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_comments
    ADD CONSTRAINT community_comments_pkey PRIMARY KEY (id);


--
-- Name: community_corrections community_corrections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_corrections
    ADD CONSTRAINT community_corrections_pkey PRIMARY KEY (id);


--
-- Name: community_moderation_checks community_moderation_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_moderation_checks
    ADD CONSTRAINT community_moderation_checks_pkey PRIMARY KEY (id);


--
-- Name: community_moderator_actions community_moderator_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_moderator_actions
    ADD CONSTRAINT community_moderator_actions_pkey PRIMARY KEY (id);


--
-- Name: community_notifications community_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_notifications
    ADD CONSTRAINT community_notifications_pkey PRIMARY KEY (id);


--
-- Name: community_notifications community_notifications_user_id_dedupe_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_notifications
    ADD CONSTRAINT community_notifications_user_id_dedupe_key_unique UNIQUE (user_id, dedupe_key);


--
-- Name: community_post_media community_post_media_news_item_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_post_media
    ADD CONSTRAINT community_post_media_news_item_id_unique UNIQUE (news_item_id);


--
-- Name: community_post_media community_post_media_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_post_media
    ADD CONSTRAINT community_post_media_pkey PRIMARY KEY (id);


--
-- Name: community_post_meta community_post_meta_news_item_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_post_meta
    ADD CONSTRAINT community_post_meta_news_item_id_unique UNIQUE (news_item_id);


--
-- Name: community_post_meta community_post_meta_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_post_meta
    ADD CONSTRAINT community_post_meta_pkey PRIMARY KEY (id);


--
-- Name: community_post_versions community_post_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_post_versions
    ADD CONSTRAINT community_post_versions_pkey PRIMARY KEY (id);


--
-- Name: community_reactions community_reactions_news_item_id_user_id_type_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_reactions
    ADD CONSTRAINT community_reactions_news_item_id_user_id_type_unique UNIQUE (news_item_id, user_id, type);


--
-- Name: community_reactions community_reactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_reactions
    ADD CONSTRAINT community_reactions_pkey PRIMARY KEY (id);


--
-- Name: community_reports community_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_reports
    ADD CONSTRAINT community_reports_pkey PRIMARY KEY (id);


--
-- Name: community_reputation_events community_reputation_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_reputation_events
    ADD CONSTRAINT community_reputation_events_pkey PRIMARY KEY (id);


--
-- Name: community_reputation_events community_reputation_events_user_id_news_item_id_event_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_reputation_events
    ADD CONSTRAINT community_reputation_events_user_id_news_item_id_event_unique UNIQUE (user_id, news_item_id, event);


--
-- Name: community_status_history community_status_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.community_status_history
    ADD CONSTRAINT community_status_history_pkey PRIMARY KEY (id);


--
-- Name: corrections corrections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.corrections
    ADD CONSTRAINT corrections_pkey PRIMARY KEY (id);


--
-- Name: dedupe_verdicts dedupe_verdicts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dedupe_verdicts
    ADD CONSTRAINT dedupe_verdicts_pkey PRIMARY KEY (id);


--
-- Name: dedupe_verdicts dedupe_verdicts_story_a_story_b_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dedupe_verdicts
    ADD CONSTRAINT dedupe_verdicts_story_a_story_b_unique UNIQUE (story_a, story_b);


--
-- Name: extraction_jobs extraction_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.extraction_jobs
    ADD CONSTRAINT extraction_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_ingestions failed_ingestions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_ingestions
    ADD CONSTRAINT failed_ingestions_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: feed_contents feed_contents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feed_contents
    ADD CONSTRAINT feed_contents_pkey PRIMARY KEY (id);


--
-- Name: feed_contents feed_contents_url_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feed_contents
    ADD CONSTRAINT feed_contents_url_hash_unique UNIQUE (url_hash);


--
-- Name: feed_ready_items feed_ready_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feed_ready_items
    ADD CONSTRAINT feed_ready_items_pkey PRIMARY KEY (id);


--
-- Name: gazetteer gazetteer_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gazetteer
    ADD CONSTRAINT gazetteer_pkey PRIMARY KEY (id);


--
-- Name: gazetteer gazetteer_source_source_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gazetteer
    ADD CONSTRAINT gazetteer_source_source_id_key UNIQUE (source, source_id);


--
-- Name: geocode_cache geocode_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geocode_cache
    ADD CONSTRAINT geocode_cache_pkey PRIMARY KEY (id);


--
-- Name: geocode_cache geocode_cache_place_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geocode_cache
    ADD CONSTRAINT geocode_cache_place_key_unique UNIQUE (place_key);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: location_aliases location_aliases_alias_text_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.location_aliases
    ADD CONSTRAINT location_aliases_alias_text_unique UNIQUE (alias_text);


--
-- Name: location_aliases location_aliases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.location_aliases
    ADD CONSTRAINT location_aliases_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: news_items news_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.news_items
    ADD CONSTRAINT news_items_pkey PRIMARY KEY (id);


--
-- Name: news_items news_items_url_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.news_items
    ADD CONSTRAINT news_items_url_unique UNIQUE (url);


--
-- Name: news_translations news_translations_news_item_id_locale_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.news_translations
    ADD CONSTRAINT news_translations_news_item_id_locale_unique UNIQUE (news_item_id, locale);


--
-- Name: news_translations news_translations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.news_translations
    ADD CONSTRAINT news_translations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: place_reviews place_reviews_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.place_reviews
    ADD CONSTRAINT place_reviews_pkey PRIMARY KEY (id);


--
-- Name: place_reviews place_reviews_place_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.place_reviews
    ADD CONSTRAINT place_reviews_place_key_unique UNIQUE (place_key);


--
-- Name: playbook_revisions playbook_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.playbook_revisions
    ADD CONSTRAINT playbook_revisions_pkey PRIMARY KEY (id);


--
-- Name: playbook_sections playbook_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.playbook_sections
    ADD CONSTRAINT playbook_sections_pkey PRIMARY KEY (id);


--
-- Name: raw_ingest raw_ingest_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_ingest
    ADD CONSTRAINT raw_ingest_pkey PRIMARY KEY (id);


--
-- Name: removals removals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.removals
    ADD CONSTRAINT removals_pkey PRIMARY KEY (id);


--
-- Name: reports reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reports
    ADD CONSTRAINT reports_pkey PRIMARY KEY (id);


--
-- Name: review_rules review_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.review_rules
    ADD CONSTRAINT review_rules_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: source_daily_stats source_daily_stats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.source_daily_stats
    ADD CONSTRAINT source_daily_stats_pkey PRIMARY KEY (id);


--
-- Name: source_daily_stats source_daily_stats_source_id_day_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.source_daily_stats
    ADD CONSTRAINT source_daily_stats_source_id_day_unique UNIQUE (source_id, day);


--
-- Name: sources sources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sources
    ADD CONSTRAINT sources_pkey PRIMARY KEY (id);


--
-- Name: sources sources_rss_url_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sources
    ADD CONSTRAINT sources_rss_url_unique UNIQUE (rss_url);


--
-- Name: story_locations story_locations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.story_locations
    ADD CONSTRAINT story_locations_pkey PRIMARY KEY (id);


--
-- Name: subcategories subcategories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subcategories
    ADD CONSTRAINT subcategories_pkey PRIMARY KEY (id);


--
-- Name: subscribers subscribers_phone_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscribers
    ADD CONSTRAINT subscribers_phone_unique UNIQUE (phone);


--
-- Name: subscribers subscribers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscribers
    ADD CONSTRAINT subscribers_pkey PRIMARY KEY (id);


--
-- Name: topic_entities topic_entities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.topic_entities
    ADD CONSTRAINT topic_entities_pkey PRIMARY KEY (id);


--
-- Name: topic_follows topic_follows_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.topic_follows
    ADD CONSTRAINT topic_follows_pkey PRIMARY KEY (id);


--
-- Name: topic_follows topic_follows_user_id_category_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.topic_follows
    ADD CONSTRAINT topic_follows_user_id_category_unique UNIQUE (user_id, category);


--
-- Name: user_blocks user_blocks_blocker_id_blocked_id_kind_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_blocks
    ADD CONSTRAINT user_blocks_blocker_id_blocked_id_kind_unique UNIQUE (blocker_id, blocked_id, kind);


--
-- Name: user_blocks user_blocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_blocks
    ADD CONSTRAINT user_blocks_pkey PRIMARY KEY (id);


--
-- Name: user_community_badges user_community_badges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_community_badges
    ADD CONSTRAINT user_community_badges_pkey PRIMARY KEY (id);


--
-- Name: user_community_badges user_community_badges_user_id_badge_detail_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_community_badges
    ADD CONSTRAINT user_community_badges_user_id_badge_detail_unique UNIQUE (user_id, badge, detail);


--
-- Name: user_follows user_follows_follower_id_followed_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_follows
    ADD CONSTRAINT user_follows_follower_id_followed_id_unique UNIQUE (follower_id, followed_id);


--
-- Name: user_follows user_follows_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_follows
    ADD CONSTRAINT user_follows_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_user_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_user_code_unique UNIQUE (user_code);


--
-- Name: ai_balance_log_provider_recorded_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_balance_log_provider_recorded_at_index ON public.ai_balance_log USING btree (provider, recorded_at);


--
-- Name: ai_processing_jobs_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_processing_jobs_news_item_id_index ON public.ai_processing_jobs USING btree (news_item_id);


--
-- Name: api_rate_limits_api_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_rate_limits_api_key_index ON public.api_rate_limits USING btree (api_key);


--
-- Name: api_usage_analytics_api_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_usage_analytics_api_key_index ON public.api_usage_analytics USING btree (api_key);


--
-- Name: api_usage_analytics_api_key_requested_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_usage_analytics_api_key_requested_at_index ON public.api_usage_analytics USING btree (api_key, requested_at);


--
-- Name: api_usage_analytics_endpoint_requested_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_usage_analytics_endpoint_requested_at_index ON public.api_usage_analytics USING btree (endpoint, requested_at);


--
-- Name: area_follows_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX area_follows_user_id_index ON public.area_follows USING btree (user_id);


--
-- Name: bench_items_expect_sub_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bench_items_expect_sub_id_index ON public.bench_items USING btree (expect_sub_id);


--
-- Name: bench_results_bench_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bench_results_bench_item_id_index ON public.bench_results USING btree (bench_item_id);


--
-- Name: blocked_urls_host_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blocked_urls_host_index ON public.blocked_urls USING btree (host);


--
-- Name: boundaries_bbox; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX boundaries_bbox ON public.boundaries USING btree (level, min_lat, max_lat, min_lng, max_lng);


--
-- Name: boundaries_iso3_level_name_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX boundaries_iso3_level_name_key_index ON public.boundaries USING btree (iso3, level, name_key);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: community_appeals_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_appeals_news_item_id_index ON public.community_appeals USING btree (news_item_id);


--
-- Name: community_appeals_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_appeals_user_id_index ON public.community_appeals USING btree (user_id);


--
-- Name: community_comment_reports_comment_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_comment_reports_comment_id_index ON public.community_comment_reports USING btree (comment_id);


--
-- Name: community_comments_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_comments_news_item_id_index ON public.community_comments USING btree (news_item_id);


--
-- Name: community_comments_parent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_comments_parent_id_index ON public.community_comments USING btree (parent_id);


--
-- Name: community_comments_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_comments_user_id_index ON public.community_comments USING btree (user_id);


--
-- Name: community_corrections_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_corrections_news_item_id_index ON public.community_corrections USING btree (news_item_id);


--
-- Name: community_corrections_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_corrections_user_id_index ON public.community_corrections USING btree (user_id);


--
-- Name: community_moderation_checks_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_moderation_checks_news_item_id_index ON public.community_moderation_checks USING btree (news_item_id);


--
-- Name: community_moderator_actions_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_moderator_actions_news_item_id_index ON public.community_moderator_actions USING btree (news_item_id);


--
-- Name: community_moderator_actions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_moderator_actions_user_id_index ON public.community_moderator_actions USING btree (user_id);


--
-- Name: community_notifications_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_notifications_user_id_index ON public.community_notifications USING btree (user_id);


--
-- Name: community_post_media_perceptual_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_post_media_perceptual_hash_index ON public.community_post_media USING btree (perceptual_hash);


--
-- Name: community_post_media_sha256_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_post_media_sha256_index ON public.community_post_media USING btree (sha256);


--
-- Name: community_post_meta_trust_status_breaking_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_post_meta_trust_status_breaking_index ON public.community_post_meta USING btree (trust_status, breaking);


--
-- Name: community_post_versions_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_post_versions_news_item_id_index ON public.community_post_versions USING btree (news_item_id);


--
-- Name: community_reactions_news_item_id_device_token_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_reactions_news_item_id_device_token_type_index ON public.community_reactions USING btree (news_item_id, device_token, type);


--
-- Name: community_reactions_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_reactions_news_item_id_index ON public.community_reactions USING btree (news_item_id);


--
-- Name: community_reports_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_reports_news_item_id_index ON public.community_reports USING btree (news_item_id);


--
-- Name: community_reputation_events_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_reputation_events_news_item_id_index ON public.community_reputation_events USING btree (news_item_id);


--
-- Name: community_reputation_events_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_reputation_events_user_id_index ON public.community_reputation_events USING btree (user_id);


--
-- Name: community_status_history_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX community_status_history_news_item_id_index ON public.community_status_history USING btree (news_item_id);


--
-- Name: corrections_field_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX corrections_field_created_at_index ON public.corrections USING btree (field, created_at);


--
-- Name: feed_active_article_relevance; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_active_article_relevance ON public.feed_ready_items USING btree (is_active, is_article, relevance_mode);


--
-- Name: feed_active_category_published; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_active_category_published ON public.feed_ready_items USING btree (is_active, primary_category, published_at);


--
-- Name: feed_active_geo; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_active_geo ON public.feed_ready_items USING btree (is_active, is_article, lat, lng);


--
-- Name: feed_active_relevance_published; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_active_relevance_published ON public.feed_ready_items USING btree (is_active, relevance_mode, published_at);


--
-- Name: feed_ready_event_window_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_ready_event_window_index ON public.feed_ready_items USING btree (is_active, event_end);


--
-- Name: feed_ready_is_active_article_precision_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_ready_is_active_article_precision_idx ON public.feed_ready_items USING btree (is_active, is_article, precision_type);


--
-- Name: feed_ready_is_active_cat_published_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_ready_is_active_cat_published_idx ON public.feed_ready_items USING btree (is_active, primary_category, published_at);


--
-- Name: feed_ready_is_active_published_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_ready_is_active_published_idx ON public.feed_ready_items USING btree (is_active, published_at);


--
-- Name: feed_ready_items_is_active_geo_state_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_ready_items_is_active_geo_state_code_index ON public.feed_ready_items USING btree (is_active, geo_state_code);


--
-- Name: feed_ready_items_is_active_is_primary_location_published_at_ind; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_ready_items_is_active_is_primary_location_published_at_ind ON public.feed_ready_items USING btree (is_active, is_primary_location, published_at);


--
-- Name: feed_ready_items_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_ready_items_news_item_id_index ON public.feed_ready_items USING btree (news_item_id);


--
-- Name: feed_ready_items_origin_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_ready_items_origin_index ON public.feed_ready_items USING btree (origin);


--
-- Name: feed_ready_items_sub_category_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feed_ready_items_sub_category_id_index ON public.feed_ready_items USING btree (sub_category_id);


--
-- Name: fri_primary_sub_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fri_primary_sub_idx ON public.feed_ready_items USING btree (primary_category, sub_category);


--
-- Name: gazetteer_alt_names_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX gazetteer_alt_names_trgm ON public.gazetteer USING gin (alt_names public.gin_trgm_ops);


--
-- Name: gazetteer_geo; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX gazetteer_geo ON public.gazetteer USING btree (lat, lng);


--
-- Name: gazetteer_name_key_btree; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX gazetteer_name_key_btree ON public.gazetteer USING btree (name_key);


--
-- Name: gazetteer_name_key_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX gazetteer_name_key_trgm ON public.gazetteer USING gin (name_key public.gin_trgm_ops);


--
-- Name: gazetteer_representatives; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX gazetteer_representatives ON public.gazetteer USING btree (id) WHERE (dup_of IS NULL);


--
-- Name: gazetteer_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX gazetteer_state ON public.gazetteer USING btree (country, state_code);


--
-- Name: gazetteer_unstamped; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX gazetteer_unstamped ON public.gazetteer USING btree (id) WHERE (stamped_at IS NULL);


--
-- Name: geocode_cache_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geocode_cache_status_index ON public.geocode_cache USING btree (status);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: location_aliases_alias_type_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX location_aliases_alias_type_is_active_index ON public.location_aliases USING btree (alias_type, is_active);


--
-- Name: news_items_contributor_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_contributor_id_created_at_index ON public.news_items USING btree (contributor_id, created_at);


--
-- Name: news_items_discarded_published_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_discarded_published_at_index ON public.news_items USING btree (discarded, published_at);


--
-- Name: news_items_duplicate_of_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_duplicate_of_index ON public.news_items USING btree (duplicate_of);


--
-- Name: news_items_event_window_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_event_window_index ON public.news_items USING btree (event_end, published_at);


--
-- Name: news_items_geo_claim_country_geo_claim_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_geo_claim_country_geo_claim_state_index ON public.news_items USING btree (geo_claim_country, geo_claim_state);


--
-- Name: news_items_geo_claim_country_published_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_geo_claim_country_published_at_index ON public.news_items USING btree (geo_claim_country, published_at);


--
-- Name: news_items_geo_country_code_geo_state_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_geo_country_code_geo_state_code_index ON public.news_items USING btree (geo_country_code, geo_state_code);


--
-- Name: news_items_geo_state_code_geo_city_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_geo_state_code_geo_city_code_index ON public.news_items USING btree (geo_state_code, geo_city_code);


--
-- Name: news_items_is_article_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_is_article_index ON public.news_items USING btree (is_article);


--
-- Name: news_items_malaysia_relevant_published_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_malaysia_relevant_published_at_index ON public.news_items USING btree (malaysia_relevant, published_at);


--
-- Name: news_items_origin_published_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_origin_published_at_index ON public.news_items USING btree (origin, published_at);


--
-- Name: news_items_published_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_published_at_index ON public.news_items USING btree (published_at);


--
-- Name: news_items_review_status_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_review_status_created_at_index ON public.news_items USING btree (review_status, created_at);


--
-- Name: news_items_sub_category_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_items_sub_category_id_index ON public.news_items USING btree (sub_category_id);


--
-- Name: news_translations_locale_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX news_translations_locale_index ON public.news_translations USING btree (locale);


--
-- Name: place_reviews_place_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX place_reviews_place_key_index ON public.place_reviews USING btree (place_key);


--
-- Name: place_reviews_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX place_reviews_status_index ON public.place_reviews USING btree (status);


--
-- Name: playbook_sections_key_country_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX playbook_sections_key_country_unique ON public.playbook_sections USING btree (key, COALESCE(country, ''::character varying));


--
-- Name: removals_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX removals_created_at_index ON public.removals USING btree (created_at);


--
-- Name: removals_reviewed_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX removals_reviewed_at_index ON public.removals USING btree (reviewed_at);


--
-- Name: review_rules_country_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX review_rules_country_index ON public.review_rules USING btree (country);


--
-- Name: review_rules_is_active_applies_to_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX review_rules_is_active_applies_to_index ON public.review_rules USING btree (is_active, applies_to);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: source_daily_stats_day_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX source_daily_stats_day_index ON public.source_daily_stats USING btree (day);


--
-- Name: sources_country_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sources_country_index ON public.sources USING btree (country);


--
-- Name: sources_discovery_status_sightings_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sources_discovery_status_sightings_index ON public.sources USING btree (discovery_status, sightings);


--
-- Name: sources_is_active_priority_tier_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sources_is_active_priority_tier_index ON public.sources USING btree (is_active, priority_tier);


--
-- Name: story_locations_news_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX story_locations_news_item_id_index ON public.story_locations USING btree (news_item_id);


--
-- Name: topic_entities_news_item_id_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX topic_entities_news_item_id_type_index ON public.topic_entities USING btree (news_item_id, type);


--
-- Name: topic_entities_normalized_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX topic_entities_normalized_name_index ON public.topic_entities USING btree (normalized_name);


--
-- Name: topic_entities_topic_cluster_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX topic_entities_topic_cluster_index ON public.topic_entities USING btree (topic_cluster);


--
-- Name: topic_follows_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX topic_follows_user_id_index ON public.topic_follows USING btree (user_id);


--
-- Name: user_community_badges_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_community_badges_user_id_index ON public.user_community_badges USING btree (user_id);


--
-- Name: user_follows_followed_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_follows_followed_id_index ON public.user_follows USING btree (followed_id);


--
-- Name: users_trusted_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_trusted_at_index ON public.users USING btree (trusted_at);


--
-- Name: users_username_lower_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX users_username_lower_unique ON public.users USING btree (lower((username)::text)) WHERE (username IS NOT NULL);


--
-- Name: ai_processing_jobs ai_processing_jobs_news_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_processing_jobs
    ADD CONSTRAINT ai_processing_jobs_news_item_id_fkey FOREIGN KEY (news_item_id) REFERENCES public.news_items(id) ON DELETE CASCADE;


--
-- Name: bench_results bench_results_bench_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bench_results
    ADD CONSTRAINT bench_results_bench_run_id_foreign FOREIGN KEY (bench_run_id) REFERENCES public.bench_runs(id) ON DELETE CASCADE;


--
-- Name: extraction_jobs extraction_jobs_news_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.extraction_jobs
    ADD CONSTRAINT extraction_jobs_news_item_id_foreign FOREIGN KEY (news_item_id) REFERENCES public.news_items(id) ON DELETE CASCADE;


--
-- Name: feed_ready_items feed_ready_items_news_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feed_ready_items
    ADD CONSTRAINT feed_ready_items_news_item_id_foreign FOREIGN KEY (news_item_id) REFERENCES public.news_items(id) ON DELETE CASCADE;


--
-- Name: playbook_revisions playbook_revisions_playbook_section_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.playbook_revisions
    ADD CONSTRAINT playbook_revisions_playbook_section_id_foreign FOREIGN KEY (playbook_section_id) REFERENCES public.playbook_sections(id) ON DELETE CASCADE;


--
-- Name: raw_ingest raw_ingest_news_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_ingest
    ADD CONSTRAINT raw_ingest_news_item_id_foreign FOREIGN KEY (news_item_id) REFERENCES public.news_items(id) ON DELETE SET NULL;


--
-- Name: reports reports_news_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reports
    ADD CONSTRAINT reports_news_item_id_foreign FOREIGN KEY (news_item_id) REFERENCES public.news_items(id) ON DELETE CASCADE;


--
-- Name: subscribers subscribers_group_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscribers
    ADD CONSTRAINT subscribers_group_id_foreign FOREIGN KEY (group_id) REFERENCES public.broadcast_groups(id) ON DELETE SET NULL;


--
-- Name: topic_entities topic_entities_news_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.topic_entities
    ADD CONSTRAINT topic_entities_news_item_id_foreign FOREIGN KEY (news_item_id) REFERENCES public.news_items(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict PNBvx4KXywzXdailIK5pcJ1Bixcc0pDwAiN4nY8699I0OMTpJUnjaWCRD8VdiQV

--
-- PostgreSQL database dump
--

\restrict mvXZihv2rROBjBcXzpxBQ7VLtn9ygDM4y8xw27ISz1JRe5rxGVUfS0CdhctXhGN

-- Dumped from database version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_04_01_070611_create_news_items_table	1
5	2026_04_01_070615_create_feed_ready_items_table	1
6	2026_04_02_150000_make_news_item_id_nullable	1
7	2026_04_03_000000_create_admins_table	1
8	2026_04_03_072332_add_subscriber_fields_to_users_table	1
9	2026_04_04_064136_create_broadcast_groups_table	1
10	2026_04_04_064136_create_subscribers_table	1
11	2026_04_04_064320_create_reports_table	1
12	2026_04_04_064411_create_failed_ingestions_table	1
13	2026_04_04_064411_create_raw_ingest_table	1
15	2026_04_04_064539_create_extraction_jobs_table	2
16	2026_04_06_000000_create_topic_entities_table	3
17	2026_04_05_000013_create_api_usage_analytics_table	4
18	2026_04_06_000001_add_topic_fields_to_news_items	5
19	2026_04_04_000000_create_broadcast_groups_table	999
20	2026_04_04_000001_create_subscribers_table	999
21	2026_04_04_000002_create_reports_table	999
22	2026_04_04_000003_create_raw_and_failed_ingest_tables	999
23	2026_04_04_000004_add_extraction_and_ai_fields	999
24	2026_04_04_000005_add_ai_enrichment_fields	999
25	2026_04_04_000006_create_raw_failed_ingest_tables	999
26	2026_04_04_000007_create_notification_logs_table	999
27	2026_04_04_000007_create_relevance_scores_table	999
28	2026_04_04_000008_add_preferences_to_subscribers	999
29	2026_04_04_000009_add_location_preferences_to_subscribers	999
30	2026_04_04_000010_add_preference_management_fields	999
31	2026_04_04_000011_create_topic_entities_table	999
32	2026_04_04_000012_add_topic_extraction_fields	999
33	2026_04_04_064604_add_fields_to_failed_ingestions_table	999
34	2026_04_04_064604_add_fields_to_raw_ingest_table	999
35	2026_04_04_064934_create_ai_processing_jobs_table	999
36	2026_04_04_085217_add_preference_fields_to_subscribers_table	999
37	2026_04_05_000000_d11_ai_enrichment_hardening	999
38	2026_04_05_000001_create_location_aliases_table	999
39	2026_04_05_000002_add_serving_fields_to_feed_ready_items	999
40	2026_04_08_000000_add_preferences_and_location_to_subscribers_table	1000
41	2026_04_12_043741_add_is_article_to_news_items	1001
42	2026_04_12_044234_add_is_article_to_ai_processing_jobs	1002
43	2026_04_19_060000_fix_ai_processing_columns	1003
44	2026_04_30_072248_add_join_date_to_subscribers_table	1004
45	2026_05_02_000001_add_feed_performance_indexes	1005
46	2026_05_02_000002_add_d19_feed_ranking_indexes	1006
47	2026_05_03_000001_repair_news_items_geo_pipeline_columns	1007
48	2026_05_03_000002_repair_location_aliases_table	1008
49	2026_05_03_000003_add_enrichment_tracking_columns	1009
50	2026_08_31_000001_create_geocode_cache_table	1010
51	2026_08_31_000002_add_sub_category_columns	1011
52	2026_08_31_000003_create_sources_table	1012
53	2026_08_31_000004_add_source_discovery	1013
54	2026_08_31_000005_create_news_translations	1014
55	2026_08_31_000006_add_fetch_recipe	1015
56	2026_08_31_000007_spec_v2_policy	1016
57	2026_08_31_000008_translate_taxonomy	1017
58	2026_08_31_000009_index_sources	1018
59	2026_08_31_000010_widen_feed_columns	1019
60	2026_08_31_000011_malaysia_relevance	1020
61	2026_09_01_000001_contributor_posts	1021
62	2026_09_01_000002_contribution_review_queue	1022
63	2026_09_01_000003_source_handbook	1023
64	2026_09_01_000004_review_rules	1024
65	2026_09_01_000005_story_locations	1025
66	2026_09_01_000006_removals	1026
67	2026_09_01_000007_blocked_urls	1027
68	2026_09_01_000008_source_country	1028
69	2026_09_01_000009_feed_contents	1029
70	2026_09_01_000010_sport_subcategories	1030
71	2026_09_01_090000_add_outlet_scale_and_confidence	1031
72	2026_09_01_100000_create_knowledge_tables	1032
73	2026_09_01_120000_bench_proposals	1033
74	2026_09_01_140000_ai_spend	1034
75	2026_09_01_150000_source_daily	1035
76	2026_09_01_160000_source_constraints	1036
77	2026_09_01_140000_create_place_reviews_table	1037
78	2026_09_01_160000_add_place_roles_to_news_items	1038
79	2026_09_02_090000_add_duplicate_of_to_news_items	1039
80	2026_09_02_120000_add_body_fingerprint_to_extraction_jobs	1040
81	2026_09_02_170000_create_dedupe_verdicts_table	1041
82	2026_09_02_200000_add_created_by_to_subcategories	1042
83	2026_09_02_210000_add_sub_category_id	1043
84	2026_09_02_060000_add_state_to_geocode_cache	1044
85	2026_09_02_120000_add_event_window	1045
86	2026_09_02_140000_index_feed_lookups	1046
87	2026_09_03_100000_create_boundaries	1047
88	2026_09_03_120000_claimed_country	1048
89	2026_09_03_140000_city_level	1049
90	2026_09_03_150000_wider_geo_codes	1050
91	2026_09_03_160000_wider_alias_codes	1051
92	2026_09_03_170000_published_precision	1052
93	2026_09_03_180000_claim_state	1053
94	2026_09_03_190000_review_suggestions	1054
95	2026_09_03_200000_proxy_placement	1055
96	2026_09_03_210000_gazetteer	1056
97	2026_09_03_220000_gazetteer_dedupe	1057
98	2026_09_04_010000_country_settings	1058
99	2026_09_04_020000_community_reports	1059
100	2026_09_04_030000_community_phase6_8	1060
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 100, true);


--
-- PostgreSQL database dump complete
--

\unrestrict mvXZihv2rROBjBcXzpxBQ7VLtn9ygDM4y8xw27ISz1JRe5rxGVUfS0CdhctXhGN

