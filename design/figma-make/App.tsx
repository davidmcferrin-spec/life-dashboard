import { FormEvent, useEffect, useMemo, useRef, useState } from "react"
import {
  Activity,
  Archive,
  ArrowDownRight,
  ArrowUpRight,
  BarChart3,
  Bell,
  Book,
  Bookmark,
  Bot,
  Brain,
  BriefcaseBusiness,
  CalendarDays,
  Check,
  CheckCircle2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  CircleDollarSign,
  ClipboardList,
  Clock3,
  Cloud,
  CloudRain,
  CloudSun,
  Command,
  CookingPot,
  Code2,
  CreditCard,
  Eye,
  EyeOff,
  FileText,
  Filter,
  Folder,
  FolderOpen,
  Gauge,
  GitBranch,
  Globe2,
  GripVertical,
  Home,
  HeartPulse,
  Inbox,
  LayoutDashboard,
  ListFilter,
  LoaderCircle,
  LockKeyhole,
  Menu,
  Mic,
  Minus,
  MoreHorizontal,
  Monitor,
  Music2,
  Moon,
  Newspaper,
  Pause,
  Pencil,
  Plane,
  Play,
  Plus,
  Package,
  Phone,
  RefreshCw,
  RotateCcw,
  Search,
  Settings,
  ShieldCheck,
  ShoppingBasket,
  ShoppingCart,
  Shuffle,
  SkipBack,
  SkipForward,
  SlidersHorizontal,
  Smartphone,
  Speaker,
  Sparkles,
  Smile,
  SquarePen,
  Tag,
  Target,
  TrendingUp,
  Trophy,
  Undo2,
  Utensils,
  Volume2,
  WalletCards,
  Wifi,
  X,
  Zap,
  ZoomIn,
  ZoomOut,
  type LucideIcon,
} from "lucide-react"

type Page =
  | "Dashboard"
  | "Music"
  | "Money"
  | "Investing"
  | "Stocks"
  | "Pantry"
  | "Meal Prep"
  | "Valut"
  | "Brain"
  | "Clipboard"
  | "CloudPad"
  | "Clock"
  | "Flights"
  | "Feeds"
  | "Grok Digest"
  | "Settings"

type ModalState = {
  open: boolean
  title: string
  description: string
  destructive?: boolean
  content?: React.ReactNode
}

const navItems: { label: Page; icon: LucideIcon }[] = [
  { label: "Dashboard", icon: LayoutDashboard },
  { label: "Music", icon: Music2 },
  { label: "Money", icon: WalletCards },
  { label: "Investing", icon: TrendingUp },
  { label: "Stocks", icon: BarChart3 },
  { label: "Pantry", icon: ShoppingBasket },
  { label: "Meal Prep", icon: CookingPot },
  { label: "Valut", icon: Book },
  { label: "Brain", icon: Brain },
  { label: "Clipboard", icon: ClipboardList },
  { label: "CloudPad", icon: Cloud },
  { label: "Clock", icon: Clock3 },
  { label: "Flights", icon: Plane },
  { label: "Feeds", icon: Inbox },
  { label: "Grok Digest", icon: Bot },
]

const formatMoney = (value: number) =>
  new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(value)

function Button({
  children,
  variant = "primary",
  icon: Icon,
  onClick,
  disabled,
  loading,
  className = "",
  type = "button",
}: {
  children?: React.ReactNode
  variant?: "primary" | "secondary" | "ghost" | "danger"
  icon?: LucideIcon
  onClick?: () => void
  disabled?: boolean
  loading?: boolean
  className?: string
  type?: "button" | "submit"
}) {
  return (
    <button type={type} className={`btn btn-${variant} ${className}`} onClick={onClick} disabled={disabled || loading}>
      {loading ? <LoaderCircle className="spin" /> : Icon ? <Icon /> : null}
      {children}
    </button>
  )
}

function IconButton({ icon: Icon, label, onClick, active }: { icon: LucideIcon; label: string; onClick?: () => void; active?: boolean }) {
  return <button className={`icon-btn ${active ? "active" : ""}`} aria-label={label} title={label} onClick={onClick}><Icon /></button>
}

function Widget({
  title,
  eyebrow,
  icon: Icon,
  children,
  className = "",
  action,
}: {
  title: string
  eyebrow?: string
  icon?: LucideIcon
  children: React.ReactNode
  className?: string
  action?: React.ReactNode
}) {
  return (
    <section className={`widget ${className}`}>
      <header className="widget-header">
        <div className="widget-title">
          {Icon && <span className="widget-icon"><Icon /></span>}
          <div>{eyebrow && <span className="eyebrow">{eyebrow}</span>}<h3>{title}</h3></div>
        </div>
        {action ?? <IconButton icon={MoreHorizontal} label={`${title} menu`} />}
      </header>
      {children}
    </section>
  )
}

function Modal({ state, close }: { state: ModalState; close: () => void }) {
  if (!state.open) return null
  return (
    <div className="modal-backdrop" onMouseDown={close}>
      <section className="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title" onMouseDown={(event) => event.stopPropagation()}>
        <header><div><span className="eyebrow">{state.destructive ? "CONFIRM ACTION" : "LIFE DASHBOARD"}</span><h2 id="modal-title">{state.title}</h2></div><IconButton icon={X} label="Close modal" onClick={close} /></header>
        <div className="modal-body">
          <p>{state.description}</p>
          {state.content}
        </div>
        <footer><Button variant="ghost" onClick={close}>Cancel</Button><Button variant={state.destructive ? "danger" : "primary"} onClick={close}>{state.destructive ? "Delete" : "Save changes"}</Button></footer>
      </section>
    </div>
  )
}

type WidgetState = "default" | "loading" | "empty" | "error" | "setup"
type BoardItem = { id: string; key: string; w: number; h: number; lockHeight?: boolean; lockPlace?: boolean; state?: WidgetState }
type CatalogWidget = { key: string; title: string; category: string; icon: LucideIcon; w: number; h: number }

const widgetCatalog: CatalogWidget[] = [
  { key: "assistant", title: "Assistant", category: "Life", icon: Brain, w: 7, h: 2 },
  { key: "habits", title: "Habits", category: "Tracking", icon: CheckCircle2, w: 3, h: 2 },
  { key: "mood", title: "Mood", category: "Tracking", icon: Smile, w: 3, h: 2 },
  { key: "goals", title: "Goals", category: "Tracking", icon: Target, w: 3, h: 2 },
  { key: "streak", title: "Streak", category: "Tracking", icon: Trophy, w: 2, h: 1 },
  { key: "stat", title: "Stat", category: "Data", icon: Activity, w: 2, h: 1 },
  { key: "chart", title: "Chart", category: "Data", icon: BarChart3, w: 4, h: 2 },
  { key: "gauge", title: "Gauge", category: "Data", icon: Gauge, w: 2, h: 1 },
  { key: "entries", title: "Recent entries", category: "Data", icon: ListFilter, w: 3, h: 2 },
  { key: "today", title: "Today", category: "Life", icon: CloudSun, w: 4, h: 2 },
  { key: "vitals", title: "System vitals", category: "Life", icon: HeartPulse, w: 3, h: 2 },
  { key: "agenda", title: "Today’s agenda", category: "Life", icon: CalendarDays, w: 3, h: 2 },
  { key: "quick", title: "Quick actions", category: "Life", icon: Zap, w: 3, h: 2 },
  { key: "calls", title: "Call log", category: "Life", icon: Phone, w: 3, h: 2 },
  { key: "shopping", title: "Shopping", category: "Life", icon: ShoppingCart, w: 3, h: 2 },
  { key: "pantryWidget", title: "Pantry", category: "Life", icon: ShoppingBasket, w: 3, h: 2 },
  { key: "digestWidget", title: "Grok Digest", category: "Life", icon: Bot, w: 3, h: 2 },
  { key: "weather", title: "Weather", category: "Life", icon: CloudRain, w: 3, h: 2 },
  { key: "todos", title: "Todos", category: "Life", icon: ClipboardList, w: 3, h: 2 },
  { key: "bookmarks", title: "Bookmarks", category: "Life", icon: Bookmark, w: 3, h: 2 },
  { key: "flightsWidget", title: "Flights", category: "Life", icon: Plane, w: 3, h: 2 },
  { key: "packages", title: "Packages", category: "Life", icon: Package, w: 3, h: 2 },
  { key: "systemStatus", title: "System status", category: "Life", icon: Wifi, w: 3, h: 2 },
  { key: "spending", title: "Spending", category: "Money", icon: CircleDollarSign, w: 3, h: 2 },
  { key: "netWorth", title: "Net worth trend", category: "Money", icon: TrendingUp, w: 4, h: 2 },
  { key: "babyStep", title: "Baby Step", category: "Money", icon: Trophy, w: 2, h: 1 },
  { key: "debt", title: "Debt snowball", category: "Money", icon: CreditCard, w: 3, h: 2 },
  { key: "foodWeek", title: "Food this week", category: "Money", icon: Utensils, w: 2, h: 1 },
  { key: "budget", title: "Budget vs actual", category: "Money", icon: BarChart3, w: 3, h: 2 },
  { key: "funds", title: "Sinking funds", category: "Money", icon: WalletCards, w: 3, h: 2 },
  { key: "bills", title: "Bills due", category: "Money", icon: CalendarDays, w: 3, h: 2 },
  { key: "retirement", title: "Retirement", category: "Money", icon: TrendingUp, w: 2, h: 1 },
  { key: "credit", title: "Credit", category: "Money", icon: CreditCard, w: 2, h: 1 },
  { key: "portfolioWidget", title: "Portfolio", category: "Money", icon: BriefcaseBusiness, w: 4, h: 2 },
  { key: "stocksWidget", title: "Stocks", category: "Money", icon: BarChart3, w: 3, h: 2 },
  { key: "knowledge", title: "Knowledge", category: "Knowledge", icon: Book, w: 3, h: 2 },
  { key: "clipboards", title: "Cloud clipboards", category: "Knowledge", icon: Cloud, w: 7, h: 2 },
  { key: "spotify", title: "Spotify", category: "Media", icon: Music2, w: 3, h: 2 },
  { key: "youtube", title: "YouTube", category: "Media", icon: Play, w: 3, h: 2 },
  { key: "reddit", title: "Reddit", category: "Media", icon: Globe2, w: 3, h: 2 },
  { key: "redditReader", title: "Reddit reader", category: "Media", icon: ListFilter, w: 3, h: 2 },
  { key: "xFeed", title: "X", category: "Media", icon: Command, w: 3, h: 2 },
  { key: "news", title: "News feed", category: "Media", icon: Newspaper, w: 7, h: 2 },
  { key: "custom", title: "Custom", category: "Custom", icon: Code2, w: 4, h: 2 },
]

const boardDefaults: BoardItem[] = [
  { id: "w-assistant", key: "assistant", w: 7, h: 2 },
  { id: "w-habits", key: "habits", w: 3, h: 2 },
  { id: "w-today", key: "today", w: 3, h: 2 },
  { id: "w-streak", key: "streak", w: 2, h: 1 },
  { id: "w-gauge", key: "gauge", w: 2, h: 1 },
  { id: "w-agenda", key: "agenda", w: 3, h: 2 },
  { id: "w-spending", key: "spending", w: 3, h: 2 },
  { id: "w-weather", key: "weather", w: 3, h: 2 },
  { id: "w-vitals", key: "vitals", w: 3, h: 2 },
  { id: "w-stocks", key: "stocksWidget", w: 3, h: 2 },
  { id: "w-knowledge", key: "knowledge", w: 3, h: 2 },
]

function WidgetStateView({ state, title }: { state: WidgetState; title: string }) {
  if (state === "loading") return <div className="widget-skeleton"><i /><i /><i /><i /></div>
  if (state === "empty") return <div className="widget-state"><Archive /><strong>No {title.toLowerCase()} yet</strong><span>New data will appear here.</span></div>
  if (state === "error") return <div className="widget-state error"><Activity /><strong>Couldn’t load this widget</strong><button>Try again</button></div>
  return <div className="widget-state"><Settings /><strong>Setup required</strong><span>Connect a data source in Settings.</span><button>Open Settings</button></div>
}

function MiniLine({ bars = false }: { bars?: boolean }) {
  return bars ? <div className="mini-bars">{[32,55,43,72,61,84,68,91,76,87].map((value,index) => <i key={index} style={{ height: `${value}%` }} />)}</div> : <svg className="mini-line" viewBox="0 0 220 52" preserveAspectRatio="none"><path d="M0 42 C24 38 30 21 53 28 S86 40 106 20 S140 8 158 19 S193 7 220 10" /></svg>
}

function WidgetContent({ type, onConfigure }: { type: string; onConfigure?: () => void }) {
  const [assistantQuery, setAssistantQuery] = useState("")
  const [assistantEngine, setAssistantEngine] = useState("Local")
  const [assistantMessage, setAssistantMessage] = useState("All systems are calm. Your next meeting starts in 42 minutes.")
  if (type === "assistant") return <div className="assistant-widget"><div className="assistant-widget-orb"><Brain /><i /></div><div className="assistant-widget-copy"><span className="live-label"><i /> ASSISTANT ONLINE</span><h2>How can I assist you?</h2><p>{assistantMessage}</p></div><form onSubmit={(event) => { event.preventDefault(); if (!assistantQuery.trim()) return; setAssistantMessage(`I’m working on “${assistantQuery.trim()}” with ${assistantEngine}.`); setAssistantQuery("") }}><Command /><input value={assistantQuery} onChange={(event) => setAssistantQuery(event.target.value)} placeholder="Ask about your day, money, notes, music or plans…" /><button><ArrowUpRight /></button></form><div className="assistant-engines"><span>SEARCH WITH</span>{["Local","Google","DDG","Bing","Grok","Claude"].map((name) => <button key={name} className={assistantEngine === name ? "active" : ""} onClick={() => setAssistantEngine(name)}>{name === "Local" && <ShieldCheck />}{name}</button>)}</div></div>
  if (type === "habits") return <div className="habit-list">{[["Morning walk",12,true],["Read 20 minutes",8,true],["No screens after 10",5,false]].map(([label,streak,done]) => <button key={String(label)} className={done ? "done" : ""}><span>{done ? <Check /> : null}</span><strong>{label}</strong><small>{streak} day streak</small></button>)}<div className="day-strip">{["M","T","W","T","F","S","S"].map((day,index) => <i key={index} className={index < 5 ? "done" : ""}>{day}</i>)}</div></div>
  if (type === "mood") return <div className="mood-widget"><div>{[1,2,3,4,5].map((score) => <button key={score} className={score === 4 ? "active" : ""}>{score}</button>)}</div><p>Today feels calm and productive.</p><MiniLine /></div>
  if (type === "goals") return <div className="goal-rows">{[["Steps","8,420 / 10k",84],["Water","7 / 8",88],["Focus","96 / 90 min",107]].map(([label,value,percent]) => <div key={String(label)}><span><strong>{label}</strong><small>{value}</small></span><div className="goal-track"><i style={{ width: `${Math.min(100,percent as number)}%` }} className={(percent as number)>100 ? "over" : ""} /></div></div>)}</div>
  if (type === "streak") return <div className="streak-widget"><Trophy /><span><strong>12</strong><small>day current</small></span><span><strong>28</strong><small>personal best</small></span></div>
  if (type === "stat") return <div className="single-stat"><strong>7h 42m</strong><span>Average sleep · 7 days</span><small className="positive"><ArrowUpRight /> 18 min</small></div>
  if (type === "chart") return <div className="chart-legend"><span><i /> Sleep</span><span><i /> Steps</span><MiniLine /></div>
  if (type === "gauge") return <div className="gauge-widget"><div><i style={{ "--gauge": "84%" } as React.CSSProperties} /><strong>8,420</strong><span>of 10,000 steps</span></div></div>
  if (type === "entries") return <div className="entry-list">{[["Today","176.4 lb","Feeling strong"],["Apr 15","176.8 lb","After walk"],["Apr 14","177.1 lb",""]].map((row) => <div key={row[0]}><time>{row[0]}</time><strong>{row[1]}</strong><span>{row[2]}</span></div>)}</div>
  if (type === "today") return <div className="today-grid">{[["Sleep","7h 42m",Clock3],["Steps","8,420",Activity],["Exercise","42 min",HeartPulse],["Weight","176.4 lb",Gauge],["Travel","Home",Home]].map(([label,value,Icon]) => <div key={label as string}><Icon /><span>{label as string}<strong>{value as string}</strong></span></div>)}</div>
  if (type === "vitals" || type === "systemStatus") return <div className="meter-list">{[["Habits done",75],["Pings up",98],["Today’s goals",84]].map(([label,value]) => <div key={String(label)}><span>{label}<strong>{value}%</strong></span><div className="progress"><i style={{ width: `${value}%` }} /></div></div>)}</div>
  if (type === "agenda") return <div className="board-agenda">{[["10:30","Product review","Open"],["13:00","Package delivery","Track"],["18:40","SFO → SEA","Flight"]].map((row) => <div key={row[0]}><time>{row[0]}</time><span><strong>{row[1]}</strong><small>Today</small></span><button>{row[2]}</button></div>)}</div>
  if (type === "quick") return <div className="quick-tiles">{[["Brief my day",Bot],["Analyze systems",Activity],["Optimize energy",Zap]].map(([label,Icon]) => <button key={label as string}><Icon /><span>{label as string}</span></button>)}</div>
  if (type === "calls") return <div className="call-list">{[["Morgan Lee","VIP · 2 min ago","vip"],["Unknown · (415) •••• 0192","Debt collector","debt"],["Dr. Chen","Returned call","urgent"]].map((row) => <button key={row[0]}><Phone /><span><strong>{row[0]}</strong><small>{row[1]}</small></span><i className={row[2]} /></button>)}</div>
  if (type === "shopping") return <div className="shopping-widget"><div className="quick-add"><Plus /><span>2 lb chicken</span></div>{[["Produce","Avocados","Spinach"],["Meat","2 lb chicken","Salmon"]].map((aisle) => <section key={aisle[0]}><span>{aisle[0]}</span>{aisle.slice(1).map((item,index) => <button key={item} className={index === 1 ? "checked" : ""}><i />{item}</button>)}</section>)}</div>
  if (type === "pantryWidget") return <div className="pantry-counts"><div>{[["Fridge",28],["Freezer",16],["Pantry",42]].map((row) => <span key={row[0]}><strong>{row[1]}</strong>{row[0]}</span>)}</div><p><span className="warning">4 use soon</span><span>3 below par</span></p></div>
  if (type === "digestWidget") return <div className="digest-mini"><span className="badge">3 NEW</span><h4>Life Dashboard v2.4 changelog</h4>{["Neural cloud camera","Music Director tools","Widget board system"].map((item) => <button key={item}><FileText />{item}<ChevronRight /></button>)}</div>
  if (type === "weather") return <div className="board-weather"><CloudSun /><strong>64°</strong><span>Clear<small>Feels like 62°</small></span><div><span>Humidity<strong>48%</strong></span><span>Wind<strong>8 mph</strong></span><span>Visibility<strong>10 mi</strong></span></div><footer>San Francisco · Updated 6 min ago</footer></div>
  if (type === "todos") return <div className="todo-widget"><div><button className="active">Today</button><button>Work</button><button>Home</button></div>{["Send review notes","Book dentist","Water plants"].map((item,index) => <label key={item}><input type="checkbox" defaultChecked={index === 1} />{item}</label>)}</div>
  if (type === "bookmarks") return <div className="bookmark-grid">{[["Design","Figma"],["Research","Are.na"],["Work","Linear"],["Read later","12 links"]].map((item) => <button key={item[0]}><Globe2 /><span><strong>{item[0]}</strong><small>{item[1]}</small></span></button>)}</div>
  if (type === "flightsWidget") return <div className="flight-mini"><Plane /><span><small>NORTHSTAR 218 · ON TIME</small><strong>SFO <i /> JFK</strong><p>May 8 · 08:40 · Gate B12</p></span></div>
  if (type === "packages") return <div className="package-list">{[["UPS","Out for delivery","1Z••••4812"],["FedEx","Arrives Friday","78••••9204"],["USPS","Label created","94••••7710"]].map((row) => <div key={row[0]}><Package /><span><strong>{row[0]}</strong><small>{row[2]}</small></span><i>{row[1]}</i></div>)}</div>
  if (type === "spending" || type === "budget") return <div className="spending-widget"><div><strong>$3,194</strong><span>of $4,200 planned</span></div>{[["Housing",42],["Food",26],["Transport",17],["Other",15]].map((row) => <span key={row[0]}><i style={{ width: `${row[1]}%` }} /><small>{row[0]} · {row[1]}%</small></span>)}</div>
  if (type === "netWorth") return <div className="worth-widget"><div><strong>$284,920</strong><span className="positive">+3.2% this year</span></div><MiniLine /><footer><span>Checking •••• 4812<strong>$12,842</strong></span><span>Brokerage •••• 7710<strong>$184,230</strong></span></footer></div>
  if (type === "babyStep") return <div className="baby-step"><span>STEP</span><strong>4</strong><p>Invest 15% for retirement</p></div>
  if (type === "debt") return <div className="debt-list">{[["Visa •••• 2048","$2,840","Aug 2025"],["Auto loan •••• 6180","$14,220","Mar 2027"],["Student loan •••• 9014","$21,480","Nov 2028"]].map((row,index) => <div key={row[0]}><span>{index+1}</span><p><strong>{row[0]}</strong><small>Target {row[2]}</small></p><b>{row[1]}</b></div>)}</div>
  if (type === "foodWeek") return <div className="cap-widget"><strong>$184</strong><span>of $250 weekly cap</span><div className="progress"><i style={{ width: "74%" }} /></div></div>
  if (type === "funds") return <div className="fund-list">{[["Travel",68],["Home repair",42],["Gifts",87]].map((row) => <div key={row[0]}><span>{row[0]}<strong>{row[1]}%</strong></span><div className="progress"><i style={{ width: `${row[1]}%` }} /></div></div>)}</div>
  if (type === "bills") return <div className="bill-list">{[["Apr 18","Pacific Energy","$94"],["Apr 20","Visa •••• 2048","$180"],["Apr 24","Internet","$72"]].map((row) => <div key={row[1]}><time>{row[0]}</time><strong>{row[1]}</strong><span>{row[2]}</span></div>)}</div>
  if (type === "retirement") return <div className="retirement-widget"><strong>12.4%</strong><span>contribution rate</span><div><i style={{ width: "83%" }} /></div><small>15% target</small></div>
  if (type === "credit") return <div className="credit-widget"><span><strong>782</strong>Excellent</span><span><strong>8%</strong>Utilization</span></div>
  if (type === "portfolioWidget") return <div className="portfolio-mini"><div><strong>$184,230</strong><span className="positive">+$1,842 today</span></div><MiniLine /><footer>{["VOO +1.2%","AAPL +0.8%","NVDA +2.4%"].map((item) => <span key={item}>{item}</span>)}</footer></div>
  if (type === "stocksWidget") return <div className="watchlist">{[["AAPL","$189.84","+0.8%"],["NVDA","$875.40","+2.4%"],["TSLA","$171.05","-1.2%"]].map((row,index) => <div key={row[0]}><strong>{row[0]}</strong><MiniLine bars={index === 2} /><span>{row[1]}<small className={row[2].startsWith("-") ? "negative" : "positive"}>{row[2]}</small></span></div>)}</div>
  if (type === "knowledge") return <div className="knowledge-list">{[["Life Dashboard","Projects","8 min"],["Personal AI systems","Research","1 hr"],["Weekly review","Daily notes","Yesterday"]].map((row) => <button key={row[0]}><FileText /><span><strong>{row[0]}</strong><small>{row[1]} · {row[2]}</small></span><ChevronRight /></button>)}</div>
  if (type === "clipboards") return <div className="clipboard-widget"><div><Cloud /><span><strong>Product launch notes</strong><small>Synced just now · Markdown</small></span><Button variant="ghost">Edit</Button><Button variant="secondary">Preview</Button></div><pre>## Today{"\n"}- Review launch checklist{"\n"}- Confirm analytics events{"\n"}- Share final brief</pre><button>Manage clipboards <ChevronRight /></button></div>
  if (type === "spotify") return <div className="spotify-widget"><span className="mini-cover tone-violet"><Music2 /></span><span><small>NOW PLAYING</small><strong>Midnight City</strong><p>M83</p></span><button><Pause /></button><footer>12h 48m this month · Top artist: Tycho</footer></div>
  if (type === "youtube" || type === "reddit" || type === "xFeed") return <div className="rotating-card"><span className="eyebrow">CARD 1 OF 3</span><h4>{type === "youtube" ? "Designing calm interfaces for complex systems" : type === "reddit" ? "How are you organizing personal AI context?" : "A practical pattern for local-first intelligence"}</h4><p>{type === "youtube" ? "Form & Function · 18 min" : type === "reddit" ? "r/LocalLLaMA · 248 comments" : "@systemsnotes · 2h"}</p><div><i /><i className="active" /><i /></div></div>
  if (type === "redditReader") return <div className="reader-list">{["Local-first agent memory","Best self-hosted dashboards","Knowledge graph patterns"].map((item,index) => <button key={item}><span>r/{["LocalLLaMA","selfhosted","PKMS"][index]}</span><strong>{item}</strong><small>{42+index*31} comments</small></button>)}</div>
  if (type === "news") return <div className="news-grid">{[["TECHNOLOGY","Personal AI moves toward local context"],["MARKETS","Indexes steady ahead of earnings"],["DESIGN","The return of calm software"]].map((row) => <article key={row[1]}><span>{row[0]}</span><strong>{row[1]}</strong><p>Fictional briefing · 6 min ago</p></article>)}</div>
  return <div className="custom-preview"><Code2 /><span><strong>Custom metric panel</strong><small>Sandboxed markup · 3 attached metrics</small></span><Button variant="secondary" icon={Pencil} onClick={onConfigure}>Open editor</Button></div>
}

function WidgetSettingsPanel({ item, update }: { item: BoardItem; update: (patch: Partial<BoardItem>) => void }) {
  return <div className="widget-settings-panel"><label className="field"><span>WIDGET STATE</span><div className="segment choices">{(["default","loading","empty","error","setup"] as WidgetState[]).map((state) => <button key={state} className={(item.state ?? "default") === state ? "active" : ""} onClick={() => update({ state })}>{state}</button>)}</div></label><label className="setting-row"><span><strong>Lock height</strong><small>Prevent vertical resizing.</small></span><button className={`toggle ${item.lockHeight ? "on" : ""}`} onClick={() => update({ lockHeight: !item.lockHeight })}><i /></button></label><label className="setting-row"><span><strong>Lock place</strong><small>Prevent this widget from moving.</small></span><button className={`toggle ${item.lockPlace ? "on" : ""}`} onClick={() => update({ lockPlace: !item.lockPlace })}><i /></button></label></div>
}

function WidgetCatalog({ add, activeKeys }: { add: (widget: CatalogWidget) => void; activeKeys: string[] }) {
  const [search, setSearch] = useState("")
  const categories = Array.from(new Set(widgetCatalog.map((widget) => widget.category)))
  return <div className="catalog-modal"><div className="search-field catalog-search"><Search /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search widgets…" /></div>{categories.map((category) => { const widgets = widgetCatalog.filter((widget) => widget.category === category && widget.title.toLowerCase().includes(search.toLowerCase())); return widgets.length ? <section key={category}><span className="eyebrow">{category.toUpperCase()}</span><div>{widgets.map((widget) => { const Icon = widget.icon; const added = activeKeys.includes(widget.key); return <button key={widget.key} disabled={added} onClick={() => add(widget)}><span><Icon /></span><span><strong>{widget.title}</strong><small>{widget.w}/7 width · {widget.h === 1 ? "Compact" : "Standard"}</small></span>{added ? <Check /> : <Plus />}</button> })}</div></section> : null })}</div>
}

function BoardWidget({ item, editing, update, remove, settings, dragStart, drop }: { item: BoardItem; editing: boolean; update: (patch: Partial<BoardItem>) => void; remove: () => void; settings: () => void; dragStart: () => void; drop: () => void }) {
  const meta = widgetCatalog.find((widget) => widget.key === item.key)!
  const Icon = meta.icon
  const [menu, setMenu] = useState(false)
  const startResize = (event: React.PointerEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()
    const board = event.currentTarget.closest(".widget-board")
    if (!board) return
    const rect = board.getBoundingClientRect()
    const columnUnit = (rect.width - 72) / 7 + 12
    const start = { x: event.clientX, y: event.clientY, w: item.w, h: item.h }
    const move = (pointer: PointerEvent) => {
      const width = Math.max(1, Math.min(7, start.w + Math.round((pointer.clientX - start.x) / columnUnit)))
      const height = item.lockHeight ? item.h : Math.max(1, Math.min(4, start.h + Math.round((pointer.clientY - start.y) / 144)))
      update({ w: width, h: height })
    }
    const stop = () => {
      window.removeEventListener("pointermove", move)
      window.removeEventListener("pointerup", stop)
    }
    window.addEventListener("pointermove", move)
    window.addEventListener("pointerup", stop)
  }
  return <section className={`board-widget ${editing ? "editing" : ""} ${item.lockPlace ? "place-locked" : ""}`} style={{ "--widget-w": item.w, "--widget-h": item.h } as React.CSSProperties} draggable={editing && !item.lockPlace} onDragStart={dragStart} onDragOver={(event) => event.preventDefault()} onDrop={drop}><header><span className="board-widget-title"><Icon /><strong>{meta.title}</strong>{(item.lockHeight || item.lockPlace) && <LockKeyhole />}{editing && <small className="widget-size">{item.w}/7</small>}</span><button className="widget-menu-trigger" onClick={() => setMenu((value) => !value)}><MoreHorizontal /></button>{menu && <div className="widget-shell-menu"><button onClick={settings}><Settings /> Settings</button><button onClick={() => update({ lockHeight: !item.lockHeight })}><LockKeyhole /> {item.lockHeight ? "Unlock height" : "Lock height"}</button><button onClick={() => update({ lockPlace: !item.lockPlace })}><LockKeyhole /> {item.lockPlace ? "Unlock place" : "Lock place"}</button><button onClick={() => update({ h: 1 })}><RefreshCw /> Fit to content</button><button className="danger" onClick={remove}><X /> Remove</button></div>}</header><div className="board-widget-body">{(item.state ?? "default") === "default" ? <WidgetContent type={item.key} onConfigure={settings} /> : <WidgetStateView state={item.state!} title={meta.title} />}</div>{editing && <button className="resize-handle" onPointerDown={startResize} aria-label={`Resize ${meta.title}`}><GripVertical /></button>}</section>
}

function Dashboard({ openModal }: { openModal: (state: Partial<ModalState>) => void }) {
  const [editing, setEditing] = useState(false)
  const [items, setItems] = useState<BoardItem[]>(boardDefaults)
  const dragged = useRef<string | null>(null)

  const updateItem = (id: string, patch: Partial<BoardItem>) => setItems((value) => value.map((item) => item.id === id ? { ...item, ...patch } : item))
  const addWidget = (widget: CatalogWidget) => setItems((value) => value.some((item) => item.key === widget.key) ? value : [...value, { id: `${widget.key}-${Date.now()}`, key: widget.key, w: widget.w, h: widget.h }])
  const moveItem = (targetId: string) => {
    if (!dragged.current || dragged.current === targetId) return
    setItems((value) => {
      const source = value.findIndex((item) => item.id === dragged.current)
      const target = value.findIndex((item) => item.id === targetId)
      const next = [...value]
      const [moving] = next.splice(source,1)
      next.splice(target,0,moving)
      return next
    })
  }

  return (
    <div className={`page dashboard-page widget-dashboard ${editing ? "layout-editing" : ""}`}>
      <div className="board-heading board-controls"><div><Button variant="secondary" icon={Plus} onClick={() => openModal({ title: "Add widget", description: "Choose a widget to add to your home board.", content: <WidgetCatalog add={addWidget} activeKeys={items.map((item) => item.key)} /> })}>Add widget</Button><Button variant={editing ? "primary" : "secondary"} icon={editing ? Check : SlidersHorizontal} onClick={() => setEditing((value) => !value)}>{editing ? "Done editing" : "Edit layout"}</Button></div></div>
      <div className="widget-board">{items.map((item) => <BoardWidget key={item.id} item={item} editing={editing} update={(patch) => updateItem(item.id,patch)} remove={() => setItems((value) => value.filter((candidate) => candidate.id !== item.id))} settings={() => openModal({ title: item.key === "custom" ? "Custom widget editor" : `${widgetCatalog.find((widget) => widget.key === item.key)?.title} settings`, description: item.key === "custom" ? "Edit sandboxed markup and attach dashboard metrics." : "Configure the widget and preview its system states.", content: item.key === "custom" ? <div className="form-stack"><label className="field"><span>SANDBOXED MARKUP</span><textarea defaultValue={"<section>\\n  <metric name=\"focus_minutes\" />\\n</section>"} /></label><label className="field"><span>ATTACHED METRICS</span><input defaultValue="focus_minutes, sleep_hours, steps" /></label><WidgetSettingsPanel item={item} update={(patch) => updateItem(item.id,patch)} /></div> : <WidgetSettingsPanel item={item} update={(patch) => updateItem(item.id,patch)} /> })} dragStart={() => { dragged.current = item.id }} drop={() => moveItem(item.id)} />)}</div>
      {items.length === 0 && <div className="empty-board"><LayoutDashboard /><h2>Your board is empty</h2><p>Add widgets from the catalog to build your command center.</p><Button icon={Plus} onClick={() => openModal({ title: "Add widget", description: "Choose your first widget.", content: <WidgetCatalog add={addWidget} activeKeys={[]} /> })}>Add widget</Button></div>}
    </div>
  )
}

type MusicTab = "Overview" | "Playlists" | "Mixtape Builder" | "Track Library" | "Seasons" | "Activity"

const musicTracks = [
  { title: "Midnight City", artist: "M83", bpm: 105, key: "B minor · 10A", energy: 78, mood: "Euphoric", season: "Summer", length: "4:03" },
  { title: "Innerbloom", artist: "RÜFÜS DU SOL", bpm: 122, key: "C minor · 5A", energy: 66, mood: "Expansive", season: "Any", length: "9:38" },
  { title: "Nightcall", artist: "Kavinsky", bpm: 91, key: "F minor · 4A", energy: 72, mood: "Nocturnal", season: "Halloween", length: "4:18" },
  { title: "Awake", artist: "Tycho", bpm: 100, key: "D major · 10B", energy: 54, mood: "Focused", season: "Spring", length: "4:44" },
  { title: "Tadow", artist: "Masego & FKJ", bpm: 120, key: "G minor · 6A", energy: 61, mood: "Smooth", season: "Summer", length: "5:01" },
  { title: "Kerala", artist: "Bonobo", bpm: 124, key: "A minor · 8A", energy: 69, mood: "Kinetic", season: "Any", length: "3:57" },
]

const demoPlaylists = [
  { name: "Neon Focus", tracks: 32, length: "2h 48m", owner: "Music Director", updated: "12 min ago", season: "Any", tone: "teal" },
  { name: "Golden Hour Drive", tracks: 24, length: "1h 52m", owner: "David", updated: "Yesterday", season: "Summer", tone: "amber" },
  { name: "After Dark", tracks: 41, length: "3h 21m", owner: "Music Director", updated: "Apr 14", season: "Halloween", tone: "violet" },
  { name: "Sunday Reset", tracks: 18, length: "1h 16m", owner: "David", updated: "Apr 12", season: "Spring", tone: "blue" },
  { name: "Winter Signal", tracks: 36, length: "2h 42m", owner: "Music Director", updated: "Jan 5", season: "Christmas", tone: "silver" },
  { name: "Deep Work Archive", tracks: 58, length: "4h 37m", owner: "David", updated: "Mar 28", season: "Any", tone: "green" },
]

function EnergyArc({ compact = false }: { compact?: boolean }) {
  return (
    <div className={`energy-arc ${compact ? "compact" : ""}`}>
      <svg viewBox="0 0 620 130" preserveAspectRatio="none" aria-label="Playlist energy arc">
        <defs><linearGradient id="musicArcArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#44d7d0" stopOpacity=".28" /><stop offset="1" stopColor="#44d7d0" stopOpacity="0" /></linearGradient></defs>
        <path className="arc-area" d="M0 110 C50 106 70 86 112 88 S170 66 214 72 S280 30 322 39 S376 18 418 29 S485 62 522 54 S575 82 620 94 L620 130 L0 130Z" />
        <path className="arc-line" d="M0 110 C50 106 70 86 112 88 S170 66 214 72 S280 30 322 39 S376 18 418 29 S485 62 522 54 S575 82 620 94" />
        {[0,112,214,322,418,522,620].map((x, index) => <circle key={x} cx={x} cy={[110,88,72,39,29,54,94][index]} r="3" />)}
      </svg>
      {!compact && <div className="arc-axis"><span>Warm open</span><span>Build</span><span>Peak</span><span>Land softly</span></div>}
    </div>
  )
}

function PlaylistCard({ playlist, view, onOpen }: { playlist: typeof demoPlaylists[number]; view: "grid" | "list"; onOpen: () => void }) {
  return (
    <button className={`playlist-card ${view}`} onClick={onOpen}>
      <span className={`playlist-cover tone-${playlist.tone}`}><Music2 /><i /></span>
      <span className="playlist-copy"><strong>{playlist.name}</strong><small>{playlist.tracks} tracks · {playlist.length}</small><span><Bot /> {playlist.owner}<i>·</i>{playlist.updated}</span></span>
      <span className="season-pill"><CloudSun /> {playlist.season}</span>
      <MoreHorizontal />
    </button>
  )
}

function PlaylistDetail({ close }: { close: () => void }) {
  return (
    <div className="music-detail">
      <button className="back-link" onClick={close}><ChevronLeft /> Back to playlists</button>
      <header className="playlist-detail-header">
        <div className="playlist-cover detail tone-teal"><Music2 /><i /></div>
        <div><span className="eyebrow">PLAYLIST · BUILT BY MUSIC DIRECTOR</span><h1>Neon Focus</h1><p>A focused electronic arc that begins spacious, builds without distraction, and lands gently. Designed for one uninterrupted deep-work block.</p><div className="detail-tags"><span className="season-pill">Any season</span><span className="badge">32 tracks</span><span className="badge">2h 48m</span></div></div>
        <div className="detail-actions"><Button variant="secondary" icon={Pencil}>Edit details</Button><Button icon={Play}>Play</Button></div>
      </header>
      <Widget title="Energy arc" eyebrow="RUNNING ORDER · BPM + ENERGY" icon={Activity}><EnergyArc /></Widget>
      <Widget title="Track sequence" eyebrow="DRAG TO REORDER" icon={ListFilter} action={<Button variant="secondary" icon={Plus}>Add tracks</Button>}>
        <div className="table-wrap music-track-table"><table><thead><tr><th /><th>#</th><th>Title</th><th>Artist</th><th>BPM</th><th>Key</th><th>Energy</th><th>Mood</th><th>Season</th><th>Length</th><th /></tr></thead><tbody>{musicTracks.map((track, index) => <tr key={track.title}><td><GripVertical /></td><td>{String(index + 1).padStart(2,"0")}</td><td><button className="play-row"><Play /></button><strong>{track.title}</strong></td><td>{track.artist}</td><td>{track.bpm}</td><td>{track.key}</td><td><span className="energy-cell"><i style={{ width: `${track.energy}%` }} /></span>{track.energy}</td><td><span className="mood-chip">{track.mood}</span></td><td><span className="season-pill">{track.season}</span></td><td>{track.length}</td><td><IconButton icon={MoreHorizontal} label={`${track.title} actions`} /></td></tr>)}</tbody></table></div>
      </Widget>
    </div>
  )
}

function MusicOverview({ setTab, openModal }: { setTab: (tab: MusicTab) => void; openModal: (state: Partial<ModalState>) => void }) {
  const [playing, setPlaying] = useState(true)
  const [device, setDevice] = useState("Office speakers")
  return (
    <div className="music-overview">
      <section className="music-hero">
        <div className="hero-album"><Music2 /><span className="hero-equalizer">{[18,34,25,46,30,52,22,41,28,49,20,36].map((height, index) => <i key={index} style={{ height }} />)}</span></div>
        <div className="hero-track"><span className="live-label"><i /> NOW PLAYING</span><h1>Midnight City</h1><p>M83 · Hurry Up, We’re Dreaming</p><div className="hero-progress"><div><i /></div><span>2:14 <b>4:03</b></span></div><div className="hero-controls"><IconButton icon={Shuffle} label="Shuffle" /><IconButton icon={SkipBack} label="Previous" /><button className="hero-play" onClick={() => setPlaying((value) => !value)}>{playing ? <Pause /> : <Play />}</button><IconButton icon={SkipForward} label="Next" /><IconButton icon={RotateCcw} label="Repeat" active /></div></div>
        <div className="hero-output"><label><Volume2 /><input type="range" defaultValue="64" /></label><span className="eyebrow">PLAYING ON</span><div className="device-picker">{[["This computer", Monitor],["David’s phone", Smartphone],["Office speakers", Speaker]].map(([label, Icon]) => <button key={label as string} className={device === label ? "active" : ""} onClick={() => setDevice(label as string)}><Icon /><span>{label as string}</span>{device === label && <Check />}</button>)}</div></div>
      </section>
      <div className="music-card-grid">
        <Widget title="Recent playlists" eyebrow="YOUR ROTATION" icon={Music2} className="span-8" action={<button className="inline-link" onClick={() => setTab("Playlists")}>View all <ChevronRight /></button>}><div className="recent-playlists">{demoPlaylists.slice(0,3).map((playlist) => <PlaylistCard key={playlist.name} playlist={playlist} view="list" onOpen={() => setTab("Playlists")} />)}</div></Widget>
        <Widget title="Quick actions" eyebrow="MUSIC TOOLS" icon={Zap} className="span-4"><div className="quick-list music-quick"><button onClick={() => setTab("Mixtape Builder")}><Sparkles /><span><strong>New mixtape</strong><small>Plan a guided sequence</small></span><ChevronRight /></button><button onClick={() => openModal({ title: "Refresh metadata", description: "Re-analyze BPM, key, energy and mood for tracks with missing or outdated metadata." })}><RefreshCw /><span><strong>Refresh metadata</strong><small>8 tracks need attention</small></span><ChevronRight /></button><button><Globe2 /><span><strong>Open in Spotify</strong><small>Launch web player</small></span><ArrowUpRight /></button></div></Widget>
        <Widget title="Built by Music Director" eyebrow="AUTONOMOUS CURATION" icon={Bot} className="span-8"><div className="director-playlists">{demoPlaylists.filter((item) => item.owner === "Music Director").map((playlist) => <div key={playlist.name}><span className={`mini-cover tone-${playlist.tone}`}><Music2 /></span><span><strong>{playlist.name}</strong><small>{playlist.tracks} tracks · {playlist.updated}</small></span><span className="season-pill">{playlist.season}</span></div>)}</div></Widget>
        <Widget title="Season windows" eyebrow="UPCOMING" icon={CalendarDays} className="span-4"><div className="season-window-list"><div><span className="season-date">OCT<strong>01</strong></span><span><strong>Halloween</strong><small>Oct 1 – Nov 2 · 42 tracks</small></span></div><div><span className="season-date">NOV<strong>28</strong></span><span><strong>Christmas</strong><small>Thanksgiving – Jan 6 · 68 tracks</small></span></div></div><button className="inline-link" onClick={() => setTab("Seasons")}>Manage seasons <ChevronRight /></button></Widget>
      </div>
    </div>
  )
}

function MusicPlaylists({ openDetail }: { openDetail: () => void }) {
  const [view, setView] = useState<"grid" | "list">("grid")
  const [search, setSearch] = useState("")
  return (
    <div><div className="music-tools"><div className="search-field music-search"><Search /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search playlists…" /></div><button className="filter-button"><Bot /> Owner <ChevronDown /></button><button className="filter-button"><CloudSun /> Season <ChevronDown /></button><button className="filter-button"><Activity /> Mood & energy <ChevronDown /></button><button className="filter-button"><CalendarDays /> Created <ChevronDown /></button><div className="segment"><button className={view === "grid" ? "active" : ""} onClick={() => setView("grid")}><LayoutDashboard /></button><button className={view === "list" ? "active" : ""} onClick={() => setView("list")}><ListFilter /></button></div></div><div className={`playlist-collection ${view}`}>{demoPlaylists.filter((playlist) => playlist.name.toLowerCase().includes(search.toLowerCase())).map((playlist) => <PlaylistCard key={playlist.name} playlist={playlist} view={view} onOpen={openDetail} />)}</div>
    </div>
  )
}

const builderSteps = ["Theme", "Prompt and arc", "Seeds", "Rules", "Review"]

function MixtapeBuilder() {
  const [step, setStep] = useState(0)
  const [arc, setArc] = useState("Peak")
  const [locked, setLocked] = useState<number[]>([0])
  return (
    <div className="mixtape-builder">
      <aside className="builder-steps"><span className="eyebrow">PLAN_MIXTAPE</span><h2>Mixtape Builder</h2>{builderSteps.map((label,index) => <button key={label} className={`${step === index ? "active" : ""} ${step > index ? "complete" : ""}`} onClick={() => setStep(index)}><span>{step > index ? <Check /> : `0${index + 1}`}</span>{label}</button>)}<div className="builder-rule"><ShieldCheck /><span><strong>Season guard active</strong>Christmas music stays within Thanksgiving–Jan 6.</span></div></aside>
      <main className="builder-form"><span className="eyebrow">STEP 0{step + 1} OF 05</span><h1>{["Set the world for this mixtape.", "Shape the emotional journey.", "Give the plan a starting signal.", "Set the musical guardrails.", "Review the proposed sequence."][step]}</h1><p>{["Name the theme and describe the moment this mix should create.", "Choose how energy should move from the first track to the last.", "Add tracks or artists that define the sound without dictating every choice.", "Control tempo, compatibility, exclusions and seasonal availability.", "Lock the tracks you love, swap what misses, then create it in Spotify."][step]}</p><div className="builder-fields">
        {step === 0 && <><label className="field"><span>THEME</span><input defaultValue="Late-night creative focus" /></label><label className="field"><span>FREE-TEXT PROMPT</span><textarea defaultValue="Electronic and alternative tracks that feel cinematic and focused. Start spacious, build toward a confident peak, then land without losing momentum." /></label><div className="field"><span>MOODS</span><div className="mood-list">{["Focused","Nocturnal","Expansive","Kinetic","Warm"].map((mood,index) => <button key={mood} className={index < 3 ? "active" : ""}>{mood}</button>)}</div></div></>}
        {step === 1 && <><div className="field"><span>ARC SHAPE</span><div className="arc-presets">{["Warm-up","Peak","Cool-down","Steady","Roller-coaster"].map((item,index) => <button key={item} className={arc === item ? "active" : ""} onClick={() => setArc(item)}><svg viewBox="0 0 80 26"><path d={["M2 22 C28 22 45 9 78 4","M2 22 C24 20 35 3 51 5 S68 13 78 18","M2 4 C28 5 45 18 78 22","M2 13 C25 11 55 15 78 12","M2 20 C15 3 28 23 41 6 S65 22 78 8"][index]} /></svg><span>{item}</span></button>)}</div></div><div className="split-fields"><label className="field"><span>TARGET TRACKS</span><input type="number" defaultValue="18" /></label><label className="field"><span>TARGET LENGTH</span><input defaultValue="1h 30m" /></label></div><EnergyArc compact /></>}
        {step === 2 && <><label className="field"><span>SEED TRACKS</span><div className="token-input"><span>Midnight City <X /></span><span>Innerbloom <X /></span><input placeholder="Search tracks…" /></div></label><label className="field"><span>SEED ARTISTS</span><div className="token-input"><span>Tycho <X /></span><span>Bonobo <X /></span><input placeholder="Search artists…" /></div></label><div className="seed-suggestion"><Sparkles /><span><strong>Music Director suggestion</strong>Add one lower-energy acoustic seed to make the opening feel more human.</span><Button variant="ghost">Add suggestion</Button></div></>}
        {step === 3 && <><div className="range-grid"><label className="field"><span>BPM RANGE</span><div className="dual-input"><input defaultValue="90" /><i>to</i><input defaultValue="128" /></div></label><label className="field"><span>ENERGY RANGE</span><div className="dual-input"><input defaultValue="42" /><i>to</i><input defaultValue="88" /></div></label></div><label className="setting-row"><span><strong>Key compatibility</strong><small>Avoid harsh Camelot wheel transitions.</small></span><button className="toggle on"><i /></button></label><label className="setting-row"><span><strong>Allow explicit tracks</strong><small>Explicit tracks are excluded by default.</small></span><button className="toggle"><i /></button></label><label className="setting-row"><span><strong>Allow Christmas outside window</strong><small>Default window: Thanksgiving through Jan 6.</small></span><button className="toggle"><i /></button></label><label className="field"><span>EXCLUDED ARTISTS OR TRACKS</span><input placeholder="Add an exclusion…" /></label></>}
        {step === 4 && <div className="review-list">{musicTracks.slice(0,5).map((track,index) => <div key={track.title}><span>{String(index+1).padStart(2,"0")}</span><button className="review-play"><Play /></button><span><strong>{track.title}</strong><small>{track.artist} · {track.bpm} BPM · {track.key}</small></span><span className="mood-chip">{track.mood}</span><IconButton icon={RefreshCw} label="Swap track" /><IconButton icon={locked.includes(index) ? LockKeyhole : Archive} label="Lock track" active={locked.includes(index)} onClick={() => setLocked((value) => value.includes(index) ? value.filter((item) => item !== index) : [...value,index])} /></div>)}</div>}
      </div><footer><Button variant="ghost" disabled={step === 0} onClick={() => setStep((value) => value - 1)}>Back</Button><span>Saved automatically</span>{step === 4 && <Button variant="secondary" icon={RefreshCw}>Regenerate unlocked</Button>}<Button onClick={() => setStep((value) => Math.min(4,value + 1))}>{step === 4 ? "Create in Spotify" : step === 3 ? "Generate plan" : "Continue"} <ChevronRight /></Button></footer></main>
      <aside className="builder-preview"><span className="eyebrow">LIVE SEQUENCE PREVIEW</span><EnergyArc compact /><div className="preview-summary"><span>Total time<strong>1h 29m</strong></span><span>Tracks<strong>18</strong></span><span>Avg. BPM<strong>112</strong></span></div><div className="preview-tracks">{musicTracks.slice(0,5).map((track,index) => <div key={track.title}><span>{String(index+1).padStart(2,"0")}</span><span><strong>{track.title}</strong><small>{track.bpm} BPM · {track.key.split(" · ")[1]}</small></span><i className={index === 2 ? "warning" : ""}>{index === 2 ? "KEY CLASH" : `+${index + 2} BPM`}</i></div>)}</div><div className="transition-score"><span><Activity /> Transition quality<strong>92%</strong></span><div className="progress"><i style={{ width: "92%" }} /></div></div></aside>
    </div>
  )
}

function TrackLibrary() {
  const [selected, setSelected] = useState<(typeof musicTracks)[number] | null>(musicTracks[0])
  return (
    <div className="library-layout"><main><div className="api-warning"><Activity /><span><strong>Spotify analysis limit approaching</strong>Metadata refreshes may be delayed for the next 14 minutes.</span><button>Dismiss</button></div><div className="music-tools"><div className="search-field music-search"><Search /><input placeholder="Search 1,842 tracks…" /></div><Button variant="secondary" icon={RefreshCw}>Re-analyze</Button><Button variant="secondary" icon={CloudSun}>Set season</Button><Button variant="secondary" icon={Plus}>Add to playlist</Button></div><Widget title="Track metadata" eyebrow="1,842 TRACKS · 18 NEED REVIEW" icon={Tag}><div className="table-wrap"><table className="library-table"><thead><tr><th><input type="checkbox" /></th><th>Track</th><th>Artist</th><th>BPM</th><th>Key / Camelot</th><th>Energy</th><th>Mood</th><th>Season</th><th>Genre</th><th>Source</th></tr></thead><tbody>{musicTracks.map((track,index) => <tr key={track.title} className={selected?.title === track.title ? "selected" : ""} onClick={() => setSelected(track)}><td><input type="checkbox" onClick={(event) => event.stopPropagation()} /></td><td><strong>{track.title}</strong></td><td>{track.artist}</td><td>{track.bpm}</td><td>{track.key}</td><td>{track.energy}%</td><td><button className="mood-chip">{track.mood} <Pencil /></button></td><td><span className="season-pill">{track.season}</span></td><td><div className="tag-cell"><span>{["Electronic","Alternative","Indie"][index%3]}</span><Plus /></div></td><td><span className={`source-pill ${index === 2 ? "manual" : ""}`}>{index === 2 ? "Manual" : "Auto"}</span></td></tr>)}</tbody></table></div></Widget></main>{selected && <aside className="track-drawer"><header><span className="eyebrow">TRACK DETAILS</span><IconButton icon={X} label="Close drawer" onClick={() => setSelected(null)} /></header><div className="drawer-cover"><Music2 /><button><Play /></button></div><h2>{selected.title}</h2><p>{selected.artist}</p><div className="waveform">{[12,28,18,35,23,42,31,49,20,38,17,44,27,34,15,30,21,39,25,18].map((height,index) => <i key={index} style={{ height }} />)}</div><div className="drawer-fields">{[["BPM",selected.bpm],["Key",selected.key],["Energy",`${selected.energy}%`],["Mood",selected.mood],["Season",selected.season],["Source","Spotify analysis"]].map(([label,value]) => <label key={label}><span>{label}</span><strong>{value}</strong><Pencil /></label>)}</div><section><span className="eyebrow">APPEARS IN</span>{["Neon Focus","Late Night Drive","Deep Work Archive"].map((playlist) => <button key={playlist}><Music2 /><span>{playlist}</span><ChevronRight /></button>)}</section></aside>}</div>
  )
}

function SeasonsPage({ openModal }: { openModal: (state: Partial<ModalState>) => void }) {
  const seasons = [["Summer","JUN 01","SEP 01","24 playlists · 186 tracks","amber"],["Halloween","OCT 01","NOV 02","4 playlists · 42 tracks","violet"],["Christmas","NOV 28","JAN 06","7 playlists · 68 tracks","green"],["Sunday Reset","Every SUN","23:59","2 playlists · 31 tracks","blue"]]
  return <div><div className="season-strip">{["JAN","FEB","MAR","APR","MAY","JUN","JUL","AUG","SEP","OCT","NOV","DEC"].map((month,index) => <span key={month} className={index >= 5 && index <= 8 ? "summer" : index >= 9 ? "holiday" : ""}>{month}</span>)}</div><div className="seasons-heading"><div><h2>Season windows</h2><p>Keep time-specific music relevant without losing manual control.</p></div><Button icon={Plus} onClick={() => openModal({ title: "Add season", description: "Create a custom listening window.", content: <div className="form-stack"><label className="field"><span>SEASON NAME</span><input placeholder="Season name" /></label><div className="split-fields"><label className="field"><span>START DATE</span><input type="date" /></label><label className="field"><span>END DATE</span><input type="date" /></label></div></div> })}>Add season</Button></div><div className="season-cards">{seasons.map(([name,start,end,content,tone]) => <article key={name}><header><span className={`season-symbol tone-${tone}`}><CloudSun /></span><IconButton icon={MoreHorizontal} label={`${name} actions`} onClick={() => openModal({ title: `Edit ${name}`, description: "Update this seasonal listening window." })} /></header><h3>{name}</h3><div className="season-range"><span><small>START</small>{start}</span><i /><span><small>END</small>{end}</span></div><p>{content}</p><footer><span>Allow outside window</span><button className={`toggle ${name === "Sunday Reset" ? "on" : ""}`}><i /></button></footer></article>)}</div></div>
}

function MusicActivity() {
  const entries = [["10:42 AM","Created “Neon Focus”","Music Director planned 32 tracks with a warm-up → peak → cool-down arc.","View"],["9:18 AM","Refreshed track metadata","BPM, key and energy were updated for 18 tracks.","Undo"],["Yesterday","Edited “Golden Hour Drive”","Added 4 tracks and removed 2 tracks with low transition quality.","View"],["Apr 14","Applied Halloween season","42 tracks and 4 playlists were assigned to Oct 1 – Nov 2.","Undo"],["Apr 12","Removed duplicate tracks","7 duplicates were removed across 3 playlists.","View"]]
  return <div className="activity-page"><div className="activity-intro"><span className="bot-mark"><Bot /></span><div><h2>Music Director activity</h2><p>A transparent history of automated playlist and metadata changes.</p></div><span className="status-badge"><i /> Monitoring</span></div><div className="activity-timeline">{entries.map(([time,title,summary,action],index) => <article key={title}><div className="timeline-mark"><span>{index === 0 ? <Sparkles /> : index === 1 ? <RefreshCw /> : <Music2 />}</span></div><time>{time}</time><div><h3>{title}</h3><p>{summary}</p></div><button>{action === "Undo" && <Undo2 />}{action}</button></article>)}</div></div>
}

function MusicPage({ openModal }: { openModal: (state: Partial<ModalState>) => void }) {
  const [tab, setTab] = useState<MusicTab>("Overview")
  const [detail, setDetail] = useState(false)
  return (
    <div className="page music-page">
      <div className="page-heading music-heading"><div><span className="eyebrow">SPOTIFY · MUSIC DIRECTOR ONLINE</span><h1>Music</h1><p>Plan, shape and maintain the soundtrack around your life.</p></div><div className="heading-actions"><span className="status-badge"><i /> Spotify connected</span><Button icon={Plus} onClick={() => { setTab("Mixtape Builder"); setDetail(false) }}>New mixtape</Button></div></div>
      <nav className="music-tabs">{(["Overview","Playlists","Mixtape Builder","Track Library","Seasons","Activity"] as MusicTab[]).map((item) => <button key={item} className={tab === item && !detail ? "active" : ""} onClick={() => { setTab(item); setDetail(false) }}>{item}</button>)}</nav>
      {detail ? <PlaylistDetail close={() => setDetail(false)} /> : tab === "Overview" ? <MusicOverview setTab={setTab} openModal={openModal} /> : tab === "Playlists" ? <MusicPlaylists openDetail={() => setDetail(true)} /> : tab === "Mixtape Builder" ? <MixtapeBuilder /> : tab === "Track Library" ? <TrackLibrary /> : tab === "Seasons" ? <SeasonsPage openModal={openModal} /> : <MusicActivity />}
    </div>
  )
}

const transactions = [
  ["Apr 16", "Aster House", "Dining", -64.2],
  ["Apr 15", "Northstar Payroll", "Income", 4820],
  ["Apr 14", "Spotify", "Subscriptions", -11.99],
  ["Apr 12", "Whole Foods", "Groceries", -126.48],
  ["Apr 11", "Pacific Energy", "Utilities", -94.16],
]

function FinancePage({ type }: { type: "Money" | "Investing" | "Stocks" }) {
  const investing = type !== "Money"
  return (
    <div className="page">
      <div className="page-heading"><div><span className="eyebrow">FINANCIAL INTELLIGENCE</span><h1>{type}</h1><p>{investing ? "Track performance, positions and market movement." : "A clear view of your accounts and spending."}</p></div><Button icon={Plus}>Add {investing ? "position" : "account"}</Button></div>
      <div className="stat-grid">
        {[
          [investing ? "Portfolio value" : "Total balance", investing ? "$184,230.62" : "$42,681.40", "+3.2%"],
          [investing ? "Day change" : "Monthly income", investing ? "+$1,842.10" : "$8,420.00", "+1.01%"],
          [investing ? "Buying power" : "Monthly spend", investing ? "$24,180.00" : "$3,194.22", "-8.4%"],
          [investing ? "Annual return" : "Savings rate", investing ? "12.8%" : "28.4%", "+2.1%"],
        ].map(([label, value, delta]) => <div className="stat-card" key={label}><span>{label}</span><strong>{value}</strong><small className={delta.startsWith("-") ? "negative" : "positive"}>{delta} <span>vs last period</span></small></div>)}
      </div>
      <div className="content-grid">
        <Widget title={investing ? "Portfolio performance" : "Cash flow"} eyebrow="LAST 6 MONTHS" icon={BarChart3} className="span-8 chart-widget">
          <div className="chart-y"><span>$200k</span><span>$150k</span><span>$100k</span><span>$50k</span></div>
          <div className="line-chart"><svg viewBox="0 0 700 190" preserveAspectRatio="none"><defs><linearGradient id="area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#44d7d0" stopOpacity=".22" /><stop offset="1" stopColor="#44d7d0" stopOpacity="0" /></linearGradient></defs><path className="chart-area" d="M0 165 C70 155 95 120 150 130 S240 150 290 105 S380 92 430 112 S520 72 570 76 S650 28 700 42 L700 190 L0 190Z" /><path className="chart-line" d="M0 165 C70 155 95 120 150 130 S240 150 290 105 S380 92 430 112 S520 72 570 76 S650 28 700 42" /></svg></div>
        </Widget>
        <Widget title="Accounts" eyebrow="CONNECTED" icon={CreditCard} className="span-4">
          <div className="account-list">{[["Apex Checking", "•••• 4812", 12842.19], ["Northstar Savings", "•••• 9204", 29839.21], ["Atlas Brokerage", "•••• 7710", 184230.62]].map(([name, digits, balance]) => <div className="account-row" key={String(name)}><span className="account-icon"><CreditCard /></span><span><strong>{name}</strong><small>{digits}</small></span><b>{formatMoney(balance as number)}</b></div>)}</div>
        </Widget>
      </div>
      <Widget title={investing ? "Holdings & watchlist" : "Recent transactions"} icon={investing ? BriefcaseBusiness : ListFilter} action={<div className="table-actions"><Button variant="ghost" icon={Filter}>Filter</Button><IconButton icon={MoreHorizontal} label="Table menu" /></div>}>
        <div className="table-wrap"><table><thead><tr>{(investing ? ["Asset", "Symbol", "Shares", "Price", "Value", "Return"] : ["Date", "Merchant", "Category", "Amount"]).map((head) => <th key={head}>{head}</th>)}</tr></thead><tbody>{investing ? [["Apple Inc.", "AAPL", "42", "$189.84", "$7,973.28", "+18.2%"], ["Vanguard S&P 500", "VOO", "86", "$472.13", "$40,602.18", "+12.6%"], ["NVIDIA", "NVDA", "28", "$875.40", "$24,511.20", "+31.4%"], ["Tesla", "TSLA", "34", "$171.05", "$5,815.70", "-8.1%"]].map((row) => <tr key={row[1]}>{row.map((cell, index) => <td key={cell} className={index === 5 ? (cell.startsWith("-") ? "negative" : "positive") : ""}>{cell}</td>)}</tr>) : transactions.map((row) => <tr key={String(row[0]) + String(row[1])}>{row.map((cell, index) => <td key={String(cell)} className={index === 3 ? ((cell as number) < 0 ? "negative" : "positive") : ""}>{index === 3 ? formatMoney(cell as number) : cell}</td>)}</tr>)}</tbody></table></div>
      </Widget>
    </div>
  )
}

const treeItems = [
  { type: "folder", name: "Projects", depth: 0, open: true },
  { type: "file", name: "Life Dashboard", depth: 1, active: true },
  { type: "file", name: "Atlas launch plan", depth: 1 },
  { type: "folder", name: "People", depth: 0, open: false },
  { type: "folder", name: "Research", depth: 0, open: true },
  { type: "file", name: "Personal AI systems", depth: 1 },
  { type: "file", name: "Calm interfaces", depth: 1 },
  { type: "folder", name: "Daily notes", depth: 0, open: false },
]

function VaultPage({ openModal }: { openModal: (state: Partial<ModalState>) => void }) {
  const [editing, setEditing] = useState(false)
  const [search, setSearch] = useState("")
  return (
    <div className="vault-page">
      <aside className="tree-pane">
        <header><div><span className="eyebrow">KNOWLEDGE</span><h2>Valut</h2></div><IconButton icon={Plus} label="New note" onClick={() => openModal({ title: "Create note", description: "Add a new note to your knowledge vault.", content: <label className="field"><span>NOTE TITLE</span><input placeholder="Untitled note" autoFocus /></label> })} /></header>
        <div className="search-field"><Search /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search notes…" /><IconButton icon={Archive} label="Collapse all" /></div>
        <div className="tree-list">
          {treeItems.filter((item) => item.name.toLowerCase().includes(search.toLowerCase())).map((item) => {
            const ItemIcon = item.type === "folder" ? (item.open ? FolderOpen : Folder) : FileText
            return <button key={item.name} className={`tree-item ${item.active ? "active" : ""}`} style={{ paddingLeft: `${12 + item.depth * 20}px` }}><ChevronRight className={item.open ? "open" : item.type === "file" ? "hidden" : ""} /><ItemIcon /><span>{item.name}</span><span className="tree-actions"><Plus /><MoreHorizontal /></span></button>
          })}
          {search && !treeItems.some((item) => item.name.toLowerCase().includes(search.toLowerCase())) && <div className="small-empty"><Search /><strong>No results</strong><span>Try another search term.</span></div>}
        </div>
      </aside>
      <main className="note-pane">
        <header className="note-header"><div className="note-title"><div className="breadcrumbs">Projects <ChevronRight /> Life Dashboard</div><h1>Life Dashboard</h1><span>Updated 8 minutes ago · <i>Saved automatically</i></span></div><div className="note-actions"><div className="segment"><button className={!editing ? "active" : ""} onClick={() => setEditing(false)}>Preview</button><button className={editing ? "active" : ""} onClick={() => setEditing(true)}>Edit</button></div><IconButton icon={MoreHorizontal} label="Note actions" /></div></header>
        {editing ? <textarea className="markdown-editor" defaultValue={"# Life Dashboard\n\nA calm personal command center for the things that matter.\n\n## Product principles\n\n- Calm over noisy\n- Context over clutter\n- Action over information\n\n```ts\nconst experience = clarity + trust\n```"} /> : <article className="markdown"><h1>Life Dashboard</h1><p className="lead">A calm personal command center for the things that matter.</p><p>This project brings daily planning, financial context, knowledge and automation into one coherent workspace.</p><h2>Product principles</h2><ul><li><strong>Calm over noisy.</strong> Information should arrive with clear priority.</li><li><strong>Context over clutter.</strong> Every surface should explain why it matters.</li><li><strong>Action over information.</strong> Insight should lead naturally to the next step.</li></ul><h2>System architecture</h2><pre><code>const experience = clarity + trust</code></pre><h2>Delivery plan</h2><table><thead><tr><th>Phase</th><th>Focus</th><th>Status</th></tr></thead><tbody><tr><td>01</td><td>Core dashboard</td><td>Active</td></tr><tr><td>02</td><td>Knowledge graph</td><td>Planned</td></tr></tbody></table></article>}
      </main>
      <aside className="context-pane">
        <section><span className="eyebrow">BACKLINKS · 3</span>{["Personal AI systems", "Product roadmap", "Weekly review"].map((item) => <button key={item}><GitBranch /><span>{item}</span><ChevronRight /></button>)}</section>
        <section><span className="eyebrow">TAGS</span><div className="chip-list"><span>product</span><span>AI</span><span>active</span></div></section>
        <section><span className="eyebrow">OUTLINE</span>{["Product principles", "System architecture", "Delivery plan"].map((item) => <button key={item}><span>{item}</span></button>)}</section>
      </aside>
    </div>
  )
}

const neuralClouds = [
  {
    name: "Knowledge Core", signal: "12.8 GB", x: 43, y: 46, scale: 1,
    nodes: [
      { id: 1, label: "Life Dashboard", type: "Project", az: 0, el: 0, radius: 108, size: 30, activity: 94, access: "write" },
      { id: 2, label: "Personal AI", type: "Topic", az: 48, el: 22, radius: 104, size: 24, activity: 82, access: "read" },
      { id: 3, label: "JARVIS UX", type: "Note", az: 96, el: -18, radius: 112, size: 22, activity: 88, access: "write" },
      { id: 4, label: "Alex Sterling", type: "Person", az: 145, el: 35, radius: 105, size: 21, activity: 52, access: "idle" },
      { id: 5, label: "Grok Digest", type: "Bot", az: 190, el: -28, radius: 110, size: 23, activity: 76, access: "read" },
      { id: 6, label: "Calm interfaces", type: "Note", az: 238, el: 14, radius: 103, size: 19, activity: 41, access: "idle" },
      { id: 7, label: "Financial goals", type: "Topic", az: 284, el: -36, radius: 109, size: 20, activity: 63, access: "read" },
      { id: 8, label: "Atlas launch", type: "Project", az: 326, el: 29, radius: 106, size: 22, activity: 71, access: "idle" },
      { id: 9, label: "Daily context", type: "Note", az: 72, el: 58, radius: 101, size: 18, activity: 67, access: "write" },
      { id: 10, label: "Memory index", type: "Topic", az: 252, el: 61, radius: 107, size: 18, activity: 58, access: "idle" },
    ],
  },
  {
    name: "Music Intelligence", signal: "4.2 GB", x: 76, y: 31, scale: .66,
    nodes: [
      { id: 11, label: "Music Director", type: "Bot", az: 0, el: 10, radius: 92, size: 26, activity: 97, access: "write" },
      { id: 12, label: "Neon Focus", type: "Project", az: 58, el: 32, radius: 88, size: 20, activity: 74, access: "read" },
      { id: 13, label: "Track metadata", type: "Topic", az: 118, el: -22, radius: 94, size: 19, activity: 86, access: "write" },
      { id: 14, label: "Season rules", type: "Note", az: 180, el: 40, radius: 89, size: 17, activity: 48, access: "idle" },
      { id: 15, label: "Energy arcs", type: "Topic", az: 242, el: -34, radius: 91, size: 18, activity: 69, access: "read" },
      { id: 16, label: "Spotify", type: "Bot", az: 306, el: 17, radius: 93, size: 21, activity: 91, access: "write" },
    ],
  },
  {
    name: "Life Signals", signal: "2.6 GB", x: 72, y: 73, scale: .58,
    nodes: [
      { id: 17, label: "Calendar", type: "Topic", az: 5, el: 12, radius: 84, size: 20, activity: 78, access: "read" },
      { id: 18, label: "Money", type: "Topic", az: 72, el: -25, radius: 82, size: 18, activity: 62, access: "idle" },
      { id: 19, label: "Flights", type: "Project", az: 143, el: 36, radius: 86, size: 19, activity: 55, access: "read" },
      { id: 20, label: "Pantry", type: "Note", az: 218, el: -31, radius: 80, size: 16, activity: 42, access: "write" },
      { id: 21, label: "Automations", type: "Bot", az: 290, el: 24, radius: 85, size: 21, activity: 89, access: "write" },
    ],
  },
]

const brainNodes = neuralClouds.flatMap((cloud) => cloud.nodes)

function BrainPage({ goToVault }: { goToVault: () => void }) {
  const [selected, setSelected] = useState(1)
  const [paused, setPaused] = useState(false)
  const [mode3d, setMode3d] = useState(true)
  const [rotation, setRotation] = useState({ x: -7, y: -12 })
  const [attachedCloud, setAttachedCloud] = useState<number | null>(0)
  const [camera, setCamera] = useState({ x: 0, y: 0, zoom: 1 })
  const [dragging, setDragging] = useState(false)
  const dragOrigin = useRef({ pointerX: 0, pointerY: 0, rotateX: 0, rotateY: 0, cameraX: 0, cameraY: 0 })
  const active = brainNodes.find((node) => node.id === selected)!
  const focusedCloud = attachedCloud === null ? null : neuralClouds[attachedCloud]

  const startPan = (event: React.PointerEvent<HTMLDivElement>) => {
    if ((event.target as HTMLElement).closest("button, input")) return
    event.currentTarget.setPointerCapture(event.pointerId)
    dragOrigin.current = { pointerX: event.clientX, pointerY: event.clientY, rotateX: rotation.x, rotateY: rotation.y, cameraX: camera.x, cameraY: camera.y }
    setDragging(true)
  }

  const movePan = (event: React.PointerEvent<HTMLDivElement>) => {
    if (!dragging) return
    if (attachedCloud === null) {
      setCamera((value) => ({
        ...value,
        x: dragOrigin.current.cameraX + event.clientX - dragOrigin.current.pointerX,
        y: dragOrigin.current.cameraY + event.clientY - dragOrigin.current.pointerY,
      }))
    } else {
      setRotation({
        x: Math.max(-48, Math.min(48, dragOrigin.current.rotateX - (event.clientY - dragOrigin.current.pointerY) * .18)),
        y: dragOrigin.current.rotateY + (event.clientX - dragOrigin.current.pointerX) * .24,
      })
    }
  }

  const resetCamera = () => {
    setRotation({ x: -7, y: -12 })
    setCamera({ x: 0, y: 0, zoom: 1 })
  }

  return (
    <div className="brain-page">
      <div
        className={`brain-canvas ${mode3d ? "mode-3d" : "mode-2d"} ${paused ? "paused" : ""} ${dragging ? "dragging" : ""} ${attachedCloud === null ? "camera-free" : "camera-attached"}`}
        style={{ "--world-rx": `${rotation.x}deg`, "--world-ry": `${rotation.y}deg`, "--camera-x": `${camera.x}px`, "--camera-y": `${camera.y}px`, "--camera-zoom": camera.zoom, "--focus-x": `${focusedCloud ? 50 - focusedCloud.x : 0}%`, "--focus-y": `${focusedCloud ? 50 - focusedCloud.y : 0}%` } as React.CSSProperties}
        onPointerDown={startPan}
        onPointerMove={movePan}
        onPointerUp={() => setDragging(false)}
        onPointerCancel={() => setDragging(false)}
        onWheel={(event) => {
          event.preventDefault()
          setCamera((value) => ({ ...value, zoom: Math.max(.55, Math.min(1.8, value.zoom - event.deltaY * .001)) }))
        }}
      >
        <div className="brain-horizon" />
        <div className="scan-beam" />
        <div className="brain-readout"><span><i /> NEURAL MESH LIVE</span><strong>21 NODES · 64 LINKS · 3 CLOUDS</strong></div>
        <div className="brain-toolbar left"><div className="brain-search"><Search /><input placeholder="Search the brain…" /></div><button className="chip active">All types</button><button className="chip">Recent</button><button className="chip">Projects</button></div>
        <div className="brain-toolbar right"><IconButton icon={ZoomIn} label="Zoom in" onClick={() => setCamera((value) => ({ ...value, zoom: Math.min(1.8, value.zoom + .12) }))} /><IconButton icon={ZoomOut} label="Zoom out" onClick={() => setCamera((value) => ({ ...value, zoom: Math.max(.55, value.zoom - .12) }))} /><IconButton icon={RefreshCw} label="Reset camera" onClick={resetCamera} /><button className={`chip ${mode3d ? "active" : ""}`} onClick={() => setMode3d((value) => !value)}>{mode3d ? "3D" : "2D"}</button><IconButton icon={paused ? Play : Pause} label="Pause live updates" active={paused} onClick={() => setPaused((value) => !value)} /></div>
        <div className="camera-dock">
          <div className="camera-mode"><Globe2 /><span><strong>{attachedCloud === null ? "FREE ROAM" : `ATTACHED · ${focusedCloud?.name.toUpperCase()}`}</strong><small>{attachedCloud === null ? "Drag to travel · scroll to zoom" : "Drag to orbit · detach to travel"}</small></span></div>
          <button className={attachedCloud === null ? "active" : ""} onClick={() => setAttachedCloud(null)}><Globe2 /> Free</button>
          {neuralClouds.map((cloud,index) => <button key={cloud.name} className={attachedCloud === index ? "active" : ""} onClick={() => { setAttachedCloud(index); setCamera((value) => ({ ...value, x: 0, y: 0 })) }}><Brain /> {cloud.name.replace(" Intelligence","").replace(" Core","")}</button>)}
        </div>
        <div className="access-source source-user"><Command /><span><strong>DAVID · USER SESSION</strong><small>READING 4 · WRITING 1</small></span><i /></div>
        <div className="access-source source-vault"><Book /><span><strong>VALUT</strong><small>SUSTAINED READ</small></span><i /></div>
        <div className="access-source source-music"><Bot /><span><strong>MUSIC DIRECTOR</strong><small>WRITING METADATA</small></span><i /></div>
        <div className="access-packet packet-user"><span>USER / READ</span></div>
        <div className="access-packet packet-vault"><span>VALUT / READ</span></div>
        <div className="access-packet packet-music"><span>BOT / WRITE</span></div>
        <div className="neural-world">
          <div className="neural-world-inner">
            {neuralClouds.map((cloud, cloudIndex) => (
              <div className={`neural-cloud cloud-${cloudIndex + 1} ${attachedCloud === cloudIndex ? "attached" : ""}`} key={cloud.name} style={{ "--cloud-x": `${cloud.x}%`, "--cloud-y": `${cloud.y}%`, "--cloud-scale": cloud.scale, "--cloud-delay": `${cloudIndex * -3.4}s` } as React.CSSProperties}>
                <div className="geodesic-shell"><i /><i /><i /><i /></div>
                <button className="cloud-core" onClick={() => { setAttachedCloud(cloudIndex); setCamera((value) => ({ ...value, x: 0, y: 0 })) }} aria-label={`Attach camera to ${cloud.name}`}><Brain /><i /></button>
                <div className="cloud-name"><strong>{cloud.name}</strong><span>{cloud.signal} · {cloud.nodes.length} nodes</span></div>
                {cloud.nodes.map((node, index) => (
                  <div className="neural-spoke" key={node.id} style={{ "--az": `${node.az}deg`, "--el": `${node.el}deg`, "--radius": `${node.radius}px`, "--node-size": `${node.size}px`, "--pulse-delay": `${index * -.38}s` } as React.CSSProperties}>
                    <i className="neural-thread" />
                    <button className={`neural-object type-${node.type.toLowerCase()} access-${node.access} ${selected === node.id ? "selected" : ""}`} onClick={() => setSelected(node.id)}>
                      <span>{node.type.slice(0,1)}</span><i />
                      <b>{node.label}<small>{node.access === "write" ? "WRITING" : node.access === "read" ? "READING" : node.type}</small></b>
                    </button>
                  </div>
                ))}
              </div>
            ))}
          </div>
        </div>
        <div className="brain-legend">{["Project", "Topic", "Note", "Person", "Bot"].map((type) => <span key={type}><i className={`type-${type.toLowerCase()}`} />{type}</span>)}</div>
      </div>
      <aside className="node-panel"><header><span className="node-type">{active.type}</span><span className="node-signal"><i /> {active.access === "write" ? "WRITE ACTIVE" : active.access === "read" ? "READ ACTIVE" : "LIVE SIGNAL"}</span></header><div className={`node-mark access-${active.access}`}><Brain /><i /></div><h2>{active.label}</h2><p>This neural object is connected across the spatial knowledge mesh and updates as its source context changes.</p><div className="io-readout"><span><ArrowDownRight /> Reads<strong>{active.access === "read" ? "18/s" : "4/s"}</strong></span><span><ArrowUpRight /> Writes<strong>{active.access === "write" ? "7/s" : "0/s"}</strong></span></div><div className="activity-meter"><span>Neural activity <strong>{active.activity}%</strong></span><div className="progress"><i style={{ width: `${active.activity}%` }} /></div></div><div className="node-meta"><span>Last updated<strong>Just now</strong></span><span>Connections<strong>{Math.max(3,Math.round(active.activity/8))} items</strong></span></div><section><span className="eyebrow">FIRING CONNECTIONS</span>{brainNodes.filter((node) => node.id !== active.id).slice(0, 4).map((node) => <button key={node.id} onClick={() => setSelected(node.id)}><i className={`type-${node.type.toLowerCase()}`} /><span>{node.label}<small>{node.type} · {node.access}</small></span><ChevronRight /></button>)}</section><footer><Button variant="secondary" icon={Book} onClick={goToVault}>Open in Valut</Button><Button icon={Activity}>Focus</Button></footer></aside>
    </div>
  )
}

function FlightsPage({ openModal }: { openModal: (state: Partial<ModalState>) => void }) {
  const flights = [
    { route: "SFO → JFK", airline: "Northstar 218", date: "May 08", time: "08:40", status: "On time", gate: "B12" },
    { route: "JFK → LHR", airline: "Atlantic 042", date: "May 14", time: "19:15", status: "Scheduled", gate: "TBD" },
  ]
  return <div className="page"><div className="page-heading"><div><span className="eyebrow">TRAVEL COMMAND</span><h1>Flights</h1><p>Upcoming journeys, status updates and travel details.</p></div><Button icon={Plus}>Add flight</Button></div><div className="flight-hero"><div><span className="eyebrow">NEXT DEPARTURE · 22 DAYS</span><h2>SFO <Plane /> JFK</h2><p>San Francisco to New York · Wednesday, May 8</p></div><span className="status-badge"><i /> On time</span></div><div className="flight-grid">{flights.map((flight) => <button className="flight-card" key={flight.route} onClick={() => openModal({ title: flight.route, description: `${flight.airline} · ${flight.date}`, content: <div className="flight-modal-details"><div><span>Departure</span><strong>{flight.time}</strong></div><div><span>Gate</span><strong>{flight.gate}</strong></div><div><span>Status</span><strong className="positive">{flight.status}</strong></div></div> })}><div className="flight-date"><strong>{flight.date.split(" ")[1]}</strong><span>{flight.date.split(" ")[0]}</span></div><div><span className="eyebrow">{flight.airline}</span><h3>{flight.route}</h3><small>{flight.time} · Gate {flight.gate}</small></div><span className="status-badge"><i /> {flight.status}</span><ChevronRight /></button>)}</div></div>
}

function PantryPage({ mealPrep, openModal }: { mealPrep: boolean; openModal: (state: Partial<ModalState>) => void }) {
  const items = [["Avocados", "3", "Fresh", "2 days"], ["Oat milk", "2 cartons", "Dairy", "8 days"], ["Chicken breast", "1.2 lb", "Protein", "3 days"], ["Basmati rice", "4.5 lb", "Grains", "90 days"], ["Baby spinach", "1 bag", "Fresh", "1 day"]]
  return <div className="page"><div className="page-heading"><div><span className="eyebrow">KITCHEN INTELLIGENCE</span><h1>{mealPrep ? "Meal Prep" : "Pantry"}</h1><p>{mealPrep ? "A practical plan built around what you already have." : "Know what you have, what is fresh and what to use next."}</p></div><Button icon={Plus} onClick={() => openModal({ title: mealPrep ? "Add meal" : "Add pantry item", description: "Add the details below.", content: <div className="form-stack"><label className="field"><span>NAME</span><input placeholder={mealPrep ? "Meal name" : "Item name"} /></label><label className="field"><span>QUANTITY</span><input placeholder="Quantity" /></label></div> })}>Add {mealPrep ? "meal" : "item"}</Button></div>{mealPrep ? <div className="meal-week">{["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"].map((day, index) => <div className="meal-day" key={day}><header><span>{day}</span><strong>APR {15 + index}</strong></header><div className="meal-slot"><span>DINNER</span><strong>{["Lemon herb chicken", "Miso salmon bowls", "Pesto pasta", "Thai basil stir-fry", "Kitchen reset"][index]}</strong><small>{index === 4 ? "Use remaining produce" : "25–35 min · Pantry ready"}</small></div><button><Plus /> Add lunch</button></div>)}</div> : <Widget title="Inventory" eyebrow="24 ITEMS · 3 EXPIRING SOON" icon={ShoppingBasket} action={<div className="table-actions"><Button variant="ghost" icon={Filter}>Filter</Button><Button variant="secondary" icon={Plus} onClick={() => openModal({ title: "Add pantry item", description: "Track a new ingredient.", content: <label className="field"><span>ITEM NAME</span><input placeholder="e.g. Tomatoes" /></label> })}>Add item</Button></div>}><div className="table-wrap"><table><thead><tr><th>Item</th><th>Quantity</th><th>Category</th><th>Best within</th><th /></tr></thead><tbody>{items.map((item) => <tr key={item[0]}><td><strong>{item[0]}</strong></td><td>{item[1]}</td><td><span className="badge">{item[2]}</span></td><td className={item[3].startsWith("1 ") || item[3].startsWith("2 ") ? "warning" : ""}>{item[3]}</td><td><IconButton icon={MoreHorizontal} label={`${item[0]} actions`} /></td></tr>)}</tbody></table></div></Widget>}</div>
}

const digestItems = [
  { date: "APR 16", title: "AI agents move from demos into daily operations", source: "Technology", summary: "Teams are shifting attention from autonomous demos toward dependable, supervised workflows that solve narrow operational problems." },
  { date: "APR 15", title: "Markets steady as earnings season begins", source: "Markets", summary: "Major indexes traded within a narrow range while investors evaluated early financial results and revised guidance." },
  { date: "APR 14", title: "The quiet return of personal knowledge systems", source: "Research", summary: "A new generation of tools is connecting notes, tasks and context without demanding constant organization from users." },
]

function DigestPage() {
  const [open, setOpen] = useState(0)
  return <div className="page digest-page"><div className="page-heading"><div><span className="eyebrow">CURATED BY GROK</span><h1>Grok Digest</h1><p>Your compact briefing across technology, markets and ideas.</p></div><IconButton icon={MoreHorizontal} label="Digest options" /></div><div className="digest-filters"><button className="chip active">All topics</button><button className="chip">Technology</button><button className="chip">Markets</button><button className="chip">Research</button><span>Updated 18 minutes ago</span></div><div className="digest-list">{digestItems.map((item, index) => <article className={`digest-item ${open === index ? "open" : ""}`} key={item.title}><header onClick={() => setOpen(open === index ? -1 : index)}><time>{item.date}</time><div><span className="eyebrow">{item.source}</span><h2>{item.title}</h2></div><IconButton icon={MoreHorizontal} label="Item actions" /></header><p>{item.summary}</p>{open === index && <div className="digest-full"><p>The signal behind this story points to a broader shift toward practical systems that prioritize reliability, user control and transparent outcomes. The next phase will be defined less by novelty and more by integration quality.</p><div><span className="badge">4 sources</span><button className="inline-link">Read full analysis <ArrowUpRight /></button></div></div>}<button className="expand-digest" onClick={() => setOpen(open === index ? -1 : index)}>{open === index ? "Collapse" : "View analysis"} <ChevronDown /></button></article>)}</div></div>
}

const setupSteps = ["Identity", "Voice", "Connections", "Routines", "Privacy", "Initialize"]

function SettingsPage({ finish }: { finish: () => void }) {
  const [tab, setTab] = useState<"Setup" | "Foundations" | "Components">("Setup")
  const [step, setStep] = useState(0)
  const [showSecret, setShowSecret] = useState(false)
  if (tab === "Foundations") return <DesignSystemPage tab={tab} setTab={setTab} />
  if (tab === "Components") return <DesignSystemPage tab={tab} setTab={setTab} />
  return (
    <div className="settings-page">
      <div className="settings-tabs"><button className="active">Setup wizard</button><button onClick={() => setTab("Foundations")}>Foundations</button><button onClick={() => setTab("Components")}>Components</button></div>
      <aside className="setup-steps"><span className="eyebrow">SYSTEM SETUP</span><h2>Configure JARVIS</h2><div>{setupSteps.map((label, index) => <button key={label} className={`${step === index ? "active" : ""} ${step > index ? "complete" : ""}`} onClick={() => setStep(index)}><span>{step > index ? <Check /> : `0${index + 1}`}</span>{label}</button>)}</div><p><ShieldCheck /> Your data is encrypted and saved automatically.</p></aside>
      <main className="setup-content"><span className="eyebrow">STEP 0{step + 1} OF 06</span><h1>{["Define how we work together.", "Choose the voice you’ll trust.", "Connect your digital world.", "Make intelligence proactive.", "You remain in control.", "Ready for initialization."][step]}</h1><p>{["Set your identity and the way your assistant communicates.", "Calibrate tone, delivery and your activation phrase.", "Add service URLs and credentials without touching a config file.", "Choose when JARVIS should prepare, optimize and assist.", "Control how information is accessed, remembered and processed.", "Review the protocol and bring your system online."][step]}</p>
        <div className="setup-form">
          {step === 0 && <><label className="field"><span>YOUR NAME</span><input defaultValue="Alex Sterling" /></label><label className="field"><span>ASSISTANT NAME</span><input defaultValue="JARVIS" /></label><div className="field"><span>COMMUNICATION STYLE</span><div className="segment choices"><button>Concise</button><button>Conversational</button><button className="active">Analytical</button></div></div></>}
          {step === 1 && <><div className="voice-calibration"><div><Mic /></div><span>VOICE CHANNEL READY</span><div className="mini-wave">{Array.from({ length: 18 }).map((_, index) => <i key={index} />)}</div></div><div className="field"><span>VOICE PROFILE</span><div className="segment choices"><button className="active">Atlas</button><button>Nova</button><button>Orion</button></div></div><Button variant="secondary" icon={Play}>Play voice sample</Button></>}
          {step === 2 && <><label className="field"><span>GROK API URL</span><input defaultValue="https://api.example.ai/v1" /></label><label className="field"><span>API SECRET</span><div className="secret-input"><input type={showSecret ? "text" : "password"} defaultValue="demo_secret_8x92k" /><IconButton icon={showSecret ? EyeOff : Eye} label="Show secret" onClick={() => setShowSecret((value) => !value)} /></div><small><LockKeyhole /> Encrypted at rest · Replace secret</small></label></>}
          {step === 3 && <div className="routine-stack">{["Morning intelligence brief", "Meeting preparation", "Adaptive focus mode", "End-of-day summary"].map((item, index) => <label key={item}><span><strong>{item}</strong><small>{index % 2 ? "Triggered when needed" : "Runs automatically"}</small></span><button className={`toggle ${index < 3 ? "on" : ""}`}><i /></button></label>)}</div>}
          {step === 4 && <div className="permission-list">{[["Calendar context", "Allowed"], ["Microphone", "While active"], ["Personal memory", "Review first"], ["Proactive actions", "Ask permission"]].map(([label, value]) => <button key={label}><span>{label}</span><strong>{value}</strong><ChevronRight /></button>)}</div>}
          {step === 5 && <div className="diagnostics">{["VOICE PROFILE", "SERVICE CONNECTIONS", "PRIVACY PROTOCOL", "ROUTINES"].map((item, index) => <div key={item}><span>{item}</span><i /><strong>{index === 3 ? "3 ACTIVE" : "VERIFIED"}</strong></div>)}<div className="ready-panel"><Sparkles /><span><strong>All systems ready</strong><small>Your setup is complete and encrypted.</small></span></div></div>}
        </div>
        <footer><Button variant="ghost" onClick={() => setStep((value) => Math.max(0, value - 1))} disabled={step === 0}>Back</Button><span>Saved automatically</span><Button onClick={() => step === 5 ? finish() : setStep((value) => value + 1)}>{step === 5 ? "Initialize system" : "Save & continue"} <ChevronRight /></Button></footer>
      </main>
      <aside className="setup-preview"><span className="eyebrow">LIVE PREVIEW</span><div className="preview-ai"><div><Brain /></div></div><span className="live-label"><i /> PROFILE ADAPTING</span><blockquote>“{step === 0 ? "Good evening, Alex. I’ll keep things analytical, clear and focused." : step === 5 ? "All systems are ready. Shall we begin?" : "Your preferences are being applied to the system."}”</blockquote><div className="config-progress"><span>Configuration <strong>{Math.round(((step + 1) / 6) * 100)}%</strong></span><div className="progress"><i style={{ width: `${((step + 1) / 6) * 100}%` }} /></div></div></aside>
    </div>
  )
}

function DesignSystemPage({ tab, setTab }: { tab: "Foundations" | "Components"; setTab: (tab: "Setup" | "Foundations" | "Components") => void }) {
  return <div className="page design-page"><div className="settings-tabs inline"><button onClick={() => setTab("Setup")}>Setup wizard</button><button className={tab === "Foundations" ? "active" : ""} onClick={() => setTab("Foundations")}>Foundations</button><button className={tab === "Components" ? "active" : ""} onClick={() => setTab("Components")}>Components</button></div><div className="page-heading"><div><span className="eyebrow">LIFE DASHBOARD UI</span><h1>{tab}</h1><p>{tab === "Foundations" ? "The shared visual language behind every screen." : "Reusable, accessible patterns with consistent states."}</p></div></div>{tab === "Foundations" ? <><Widget title="Color tokens" eyebrow="SEMANTIC PALETTE"><div className="swatch-grid">{[["Background", "#080B0C"],["Surface", "#0E1315"],["Raised", "#141B1D"],["Border", "#253033"],["Text primary", "#E8EFEF"],["Text secondary", "#829092"],["Accent teal", "#44D7D0"],["Success", "#62C99A"],["Warning", "#E3B45F"],["Danger", "#E06E75"]].map(([name, value]) => <div className="swatch" key={name}><i style={{ background: value }} /><span><strong>{name}</strong><small>{value}</small></span></div>)}</div></Widget><div className="token-grid"><Widget title="Typography"><div className="type-spec"><h1>Display / 32</h1><h2>Heading / 24</h2><h3>Title / 16</h3><p>Body / 14 — calm, legible and precise.</p><small>LABEL / 11 / MEDIUM</small></div></Widget><Widget title="Spacing & radius"><div className="spacing-spec">{[4,8,12,16,24,32].map((value) => <div key={value}><i style={{ width: value }} /><span>{value}px</span></div>)}</div><div className="radius-spec"><i /><i /><i /><span>6 / 10 / 14</span></div></Widget></div></> : <><Widget title="Buttons" eyebrow="DEFAULT · HOVER · FOCUS · DISABLED"><div className="component-row"><Button>Primary</Button><Button variant="secondary">Secondary</Button><Button variant="ghost">Ghost</Button><Button variant="danger">Danger</Button><Button loading>Loading</Button><Button disabled>Disabled</Button></div></Widget><div className="token-grid"><Widget title="Inputs & selections"><div className="form-stack"><label className="field"><span>DEFAULT INPUT</span><input placeholder="Enter a value…" /></label><label className="field error"><span>ERROR STATE</span><input defaultValue="Invalid value" /><small>This value needs attention.</small></label><div className="segment choices"><button className="active">Daily</button><button>Weekly</button><button>Monthly</button></div></div></Widget><Widget title="Badges, chips & toggles"><div className="component-row"><span className="badge">Default</span><span className="status-badge"><i /> Active</span><span className="badge warning">Warning</span><button className="chip active">Selected chip</button><button className="toggle on"><i /></button><button className="toggle"><i /></button></div></Widget></div><Widget title="Feedback"><div className="feedback-grid"><div className="toast"><Check /><span><strong>Changes saved</strong><small>Your preferences are up to date.</small></span><X /></div><div className="skeleton-stack"><i /><i /><i /></div><div className="empty-mini"><Archive /><strong>Nothing here yet</strong><span>New items will appear here.</span></div></div></Widget></>}</div>
}

function GenericPage({ page }: { page: Page }) {
  const Icon = navItems.find((item) => item.label === page)?.icon ?? Sparkles
  return <div className="page"><div className="page-heading"><div><span className="eyebrow">LIFE DASHBOARD</span><h1>{page}</h1><p>Your {page.toLowerCase()} workspace is ready for your next move.</p></div><Button icon={Plus}>New item</Button></div><div className="generic-grid"><Widget title={`${page} overview`} icon={Icon} className="span-8"><div className="empty-state"><span><Icon /></span><h2>A calm place for {page.toLowerCase()}</h2><p>This workspace is connected to the rest of your command center and ready for your content.</p><Button variant="secondary" icon={Plus}>Add your first item</Button></div></Widget><Widget title="System context" icon={Activity} className="span-4"><div className="context-stats"><span>Last sync<strong>Just now</strong></span><span>Connected items<strong>0</strong></span><span>Status<strong className="positive">Ready</strong></span></div></Widget></div></div>
}

function SpotifyPlayer() {
  const [playing, setPlaying] = useState(true)
  const [expanded, setExpanded] = useState(false)
  const [position, setPosition] = useState({ x: 0, y: 0 })
  const [dragging, setDragging] = useState(false)
  const dragOrigin = useRef({ pointerX: 0, pointerY: 0, x: 0, y: 0 })

  const startDrag = (event: React.PointerEvent<HTMLButtonElement>) => {
    event.currentTarget.setPointerCapture(event.pointerId)
    dragOrigin.current = { pointerX: event.clientX, pointerY: event.clientY, x: position.x, y: position.y }
    setDragging(true)
  }

  const moveDrag = (event: React.PointerEvent<HTMLButtonElement>) => {
    if (!dragging) return
    setPosition({
      x: dragOrigin.current.x + event.clientX - dragOrigin.current.pointerX,
      y: dragOrigin.current.y + event.clientY - dragOrigin.current.pointerY,
    })
  }

  return (
    <div className={`spotify-player ${expanded ? "expanded" : ""} ${dragging ? "dragging" : ""}`} style={{ transform: `translate3d(${position.x}px, ${position.y}px, 0)` }}>
      {expanded && <div className="player-expanded">
        <div className="expanded-visual"><div className="record-art"><Music2 /><i /></div><span className="equalizer">{[8,15,11,20,13,18,9,16,12,19,10,14].map((height, index) => <i key={index} style={{ height }} />)}</span></div>
        <div className="expanded-track"><span className="eyebrow">NOW PLAYING</span><h3>Midnight City</h3><p>M83 · Hurry Up, We’re Dreaming</p><div className="track-progress"><div><i /></div><span>2:14 <b>4:03</b></span></div></div>
        <div className="expanded-device"><Wifi /><span><small>PLAYING ON</small><strong>Office speakers</strong></span><button>Change</button></div>
        <div className="up-next"><span className="eyebrow">UP NEXT</span><div><span>01</span><p><strong>Innerbloom</strong><small>RÜFÜS DU SOL</small></p><time>9:38</time></div><div><span>02</span><p><strong>Nightcall</strong><small>Kavinsky</small></p><time>4:18</time></div></div>
      </div>}
      <button className="player-drag-handle" aria-label="Move music player" title="Drag to move" onPointerDown={startDrag} onPointerMove={moveDrag} onPointerUp={() => setDragging(false)} onPointerCancel={() => setDragging(false)}><GripVertical /></button>
      <button className="album-art" onClick={() => setExpanded((value) => !value)}><Music2 /></button>
      <div className="track-info"><strong>Midnight City</strong><span>M83 · Hurry Up, We’re Dreaming</span></div>
      <div className="player-controls"><IconButton icon={SkipBack} label="Previous" /><IconButton icon={playing ? Pause : Play} label={playing ? "Pause" : "Play"} onClick={() => setPlaying((value) => !value)} active /><IconButton icon={SkipForward} label="Next" /></div>
      <div className="player-more"><IconButton icon={Wifi} label="Choose device" /><IconButton icon={ChevronDown} label={expanded ? "Collapse player" : "Expand player"} onClick={() => setExpanded((value) => !value)} /></div>
    </div>
  )
}

export default function App() {
  const [page, setPage] = useState<Page>("Dashboard")
  const [collapsed, setCollapsed] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const [theme, setTheme] = useState<"light" | "dark" | "jarvis">("jarvis")
  const [now, setNow] = useState(new Date())
  const [modal, setModal] = useState<ModalState>({ open: false, title: "", description: "" })

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 1000)
    return () => window.clearInterval(timer)
  }, [])

  const openModal = (state: Partial<ModalState>) => setModal({ open: true, title: state.title ?? "", description: state.description ?? "", destructive: state.destructive, content: state.content })
  const navigate = (next: Page) => { setPage(next); setMobileOpen(false) }
  const cycleTheme = () => setTheme((value) => value === "jarvis" ? "light" : value === "light" ? "dark" : "jarvis")
  const ThemeIcon = theme === "light" ? CloudSun : theme === "dark" ? Moon : Sparkles
  const pageContent = useMemo(() => {
    if (page === "Dashboard") return <Dashboard openModal={openModal} />
    if (page === "Music") return <MusicPage openModal={openModal} />
    if (page === "Money" || page === "Investing" || page === "Stocks") return <FinancePage type={page} />
    if (page === "Valut") return <VaultPage openModal={openModal} />
    if (page === "Brain") return <BrainPage goToVault={() => navigate("Valut")} />
    if (page === "Flights") return <FlightsPage openModal={openModal} />
    if (page === "Pantry" || page === "Meal Prep") return <PantryPage mealPrep={page === "Meal Prep"} openModal={openModal} />
    if (page === "Grok Digest") return <DigestPage />
    if (page === "Settings") return <SettingsPage finish={() => navigate("Dashboard")} />
    return <GenericPage page={page} />
  }, [page])

  return (
    <main className={`app-shell theme-${theme} ${collapsed ? "sidebar-collapsed" : ""}`}>
      <div className="ambient-grid" />
      <button className="mobile-menu" onClick={() => setMobileOpen(true)} aria-label="Open navigation"><Menu /></button>
      {mobileOpen && <button className="mobile-scrim" onClick={() => setMobileOpen(false)} aria-label="Close navigation" />}
      <aside className={`sidebar ${mobileOpen ? "mobile-open" : ""}`}>
        <header className="brand"><span><Sparkles /></span><strong>LIFE<span>.</span></strong><IconButton icon={collapsed ? ChevronRight : ChevronLeft} label="Collapse sidebar" onClick={() => setCollapsed((value) => !value)} /></header>
        <nav>{navItems.map(({ label, icon: Icon }) => <button key={label} className={page === label ? "active" : ""} onClick={() => navigate(label)} title={collapsed ? label : undefined}><Icon /><span>{label}</span>{label === "Grok Digest" && <i>3</i>}</button>)}</nav>
        <div className="sidebar-bottom"><button className={page === "Settings" ? "active" : ""} onClick={() => navigate("Settings")}><Settings /><span>Settings</span></button><div className="user-card"><div className="avatar">AS</div><span><strong>Alex Sterling</strong><small>Pro workspace</small></span><MoreHorizontal /></div></div>
      </aside>
      <section className="main-shell">
        <header className="topbar"><div className="breadcrumb"><span>Life Dashboard</span><ChevronRight /><strong>{page}</strong></div><div className="topbar-actions"><div className="global-search"><Search /><span>Search anything</span><kbd>⌘ K</kbd></div><button className="mode-switch" onClick={cycleTheme} aria-label={`Current theme: ${theme}. Change theme.`} title="Change appearance"><ThemeIcon /><span>{theme}</span></button><IconButton icon={Bell} label="Notifications" /><div className="top-time"><strong>{now.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}</strong><span>{now.toLocaleDateString([], { weekday: "short", month: "short", day: "numeric" })}</span></div></div></header>
        <div className={`content-area ${page === "Valut" || page === "Brain" ? "edge-to-edge" : ""}`}>{pageContent}</div>
      </section>
      <SpotifyPlayer />
      <Modal state={modal} close={() => setModal((value) => ({ ...value, open: false }))} />
    </main>
  )
}
