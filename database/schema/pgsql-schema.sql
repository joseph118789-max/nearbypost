--
-- PostgreSQL database dump
--

\restrict YJfU7ICmChFYZ253Tdv2MEMOFMMp8iylnNPpnrh9SnWOHJuP1NyK04x4wp6cGgo

-- Dumped from database version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)

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
    updated_at timestamp(0) without time zone
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
-- Name: feed_ready_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.feed_ready_items (
    id bigint NOT NULL,
    news_item_id bigint,
    title character varying(255) NOT NULL,
    summary text,
    source character varying(255),
    url character varying(255) NOT NULL,
    published_at timestamp(0) without time zone,
    primary_category character varying(255),
    secondary_category character varying(255),
    location_label character varying(255),
    lat numeric(10,7),
    lng numeric(10,7),
    precision_type character varying(255),
    distance_km numeric(8,2),
    relevance_mode character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
    url character varying(255) NOT NULL,
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
    location_label character varying(255)
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
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


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
-- Name: api_rate_limits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_rate_limits ALTER COLUMN id SET DEFAULT nextval('public.api_rate_limits_id_seq'::regclass);


--
-- Name: api_usage_analytics id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_usage_analytics ALTER COLUMN id SET DEFAULT nextval('public.api_usage_analytics_id_seq'::regclass);


--
-- Name: broadcast_groups id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_groups ALTER COLUMN id SET DEFAULT nextval('public.broadcast_groups_id_seq'::regclass);


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
-- Name: feed_ready_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feed_ready_items ALTER COLUMN id SET DEFAULT nextval('public.feed_ready_items_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: news_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.news_items ALTER COLUMN id SET DEFAULT nextval('public.news_items_id_seq'::regclass);


--
-- Name: raw_ingest id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_ingest ALTER COLUMN id SET DEFAULT nextval('public.raw_ingest_id_seq'::regclass);


--
-- Name: reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reports ALTER COLUMN id SET DEFAULT nextval('public.reports_id_seq'::regclass);


--
-- Name: subscribers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscribers ALTER COLUMN id SET DEFAULT nextval('public.subscribers_id_seq'::regclass);


--
-- Name: topic_entities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.topic_entities ALTER COLUMN id SET DEFAULT nextval('public.topic_entities_id_seq'::regclass);


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
-- Name: feed_ready_items feed_ready_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feed_ready_items
    ADD CONSTRAINT feed_ready_items_pkey PRIMARY KEY (id);


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
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: raw_ingest raw_ingest_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_ingest
    ADD CONSTRAINT raw_ingest_pkey PRIMARY KEY (id);


--
-- Name: reports reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reports
    ADD CONSTRAINT reports_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


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
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


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

\unrestrict YJfU7ICmChFYZ253Tdv2MEMOFMMp8iylnNPpnrh9SnWOHJuP1NyK04x4wp6cGgo

--
-- PostgreSQL database dump
--

\restrict 0mCWZ54unIsPQhhlrSC6GcrivkZa37axyBfMaFs5VcUA9GyfnfdVcm19piq3g1B

-- Dumped from database version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)

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
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 40, true);


--
-- PostgreSQL database dump complete
--

\unrestrict 0mCWZ54unIsPQhhlrSC6GcrivkZa37axyBfMaFs5VcUA9GyfnfdVcm19piq3g1B

