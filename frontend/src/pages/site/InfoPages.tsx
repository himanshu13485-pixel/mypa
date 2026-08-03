import { Link } from 'react-router-dom'
import { SitePage } from './SiteShell'

export function AboutPage() {
  return (
    <SitePage title="About Us">
      <p>
        <b>Netvork</b> is a client operations platform built on one belief: a service team should run its
        entire book of business — every client, every task, every conversation, every document — from a
        single surface instead of eight disconnected subscriptions.
      </p>
      <p>
        Everything in Netvork orbits one object: the client record. Tasks and follow-ups, reminders,
        internal notes, real-time chat and calls, meetings, screen sharing, file storage, money ledgers and
        reporting all attach to it — so the answer to “where are we with this client?” is always one page,
        never a spreadsheet reconciliation.
      </p>
      <p>
        We build privacy-first: internal notes never reach the account they describe, permissions are
        enforced on the server on every request, and every administrative action lands in an immutable
        audit log.
      </p>
      <p>
        Netvork is proudly built for service teams — agencies, consultants, brokers, and every business
        whose product is a relationship.
      </p>
      <p className="italic text-slate-400">One App. Every Task. Every Connection.</p>
    </SitePage>
  )
}

export function ContactPage() {
  return (
    <SitePage title="Contact Us">
      <p>We read everything. Pick the channel that fits:</p>
      <ul className="list-disc space-y-2 pl-5">
        <li>
          <b>Support &amp; questions:</b>{' '}
          <a className="text-[#6CE9FF] hover:underline" href="mailto:support@netvork.app">support@netvork.app</a>
        </li>
        <li>
          <b>Sales &amp; plans:</b>{' '}
          <a className="text-[#6CE9FF] hover:underline" href="mailto:sales@netvork.app">sales@netvork.app</a>{' '}
          — or see <Link className="text-[#6CE9FF] hover:underline" to="/pricing">Pricing</Link>
        </li>
        <li>
          <b>Security reports:</b>{' '}
          <a className="text-[#6CE9FF] hover:underline" href="mailto:security@netvork.app">security@netvork.app</a>{' '}
          — responsible disclosure appreciated; we respond fast.
        </li>
      </ul>
      <p>
        Existing customers can also reach their assigned salesperson directly inside the app — Connections
        → your account manager.
      </p>
    </SitePage>
  )
}

export function TermsPage() {
  return (
    <SitePage title="Terms & Conditions">
      <p className="font-mono text-xs uppercase tracking-widest text-slate-500">Last updated: 3 August 2026</p>
      <h2 className="text-lg font-semibold text-white">1. The service</h2>
      <p>
        Netvork provides a client-operations platform: accounts, tasks, reminders, messaging, calls,
        meetings, screen sharing, file storage, project ledgers and reporting. Features vary by plan as
        described on the <Link className="text-[#6CE9FF] hover:underline" to="/pricing">Pricing</Link> page.
      </p>
      <h2 className="text-lg font-semibold text-white">2. Your account</h2>
      <p>
        You are responsible for the accuracy of your registration details, for keeping your credentials
        confidential, and for all activity under your account. Accounts require a verified email address.
      </p>
      <h2 className="text-lg font-semibold text-white">3. Acceptable use</h2>
      <p>
        You may not use Netvork to send spam, distribute malware (uploads are actively screened), harass
        others, infringe intellectual property, or break any applicable law. We may suspend accounts that
        violate these rules; moderation decisions are logged and reviewable.
      </p>
      <h2 className="text-lg font-semibold text-white">4. Your content</h2>
      <p>
        Your data stays yours. We process it solely to operate the service. You can export your data (CSV
        for ledgers and reports, file downloads for documents) at any time.
      </p>
      <h2 className="text-lg font-semibold text-white">5. Subscriptions &amp; payment</h2>
      <p>
        Paid plans bill through our payment provider. Fees are exclusive of applicable taxes shown at
        checkout. You can cancel any time; access continues to the end of the paid period. Refunds are
        handled per the plan terms shown at purchase.
      </p>
      <h2 className="text-lg font-semibold text-white">6. Availability &amp; liability</h2>
      <p>
        We target high availability but the service is provided “as is” without warranty of uninterrupted
        operation. To the maximum extent permitted by law, our aggregate liability is limited to the fees
        you paid in the twelve months preceding the claim.
      </p>
      <h2 className="text-lg font-semibold text-white">7. Changes</h2>
      <p>
        We may update these terms; material changes are announced in-app at least 14 days in advance.
        Continued use after the effective date constitutes acceptance.
      </p>
    </SitePage>
  )
}

export function PrivacyPage() {
  return (
    <SitePage title="Privacy Policy">
      <p className="font-mono text-xs uppercase tracking-widest text-slate-500">Last updated: 3 August 2026</p>
      <h2 className="text-lg font-semibold text-white">What we collect</h2>
      <p>
        Account data (name, email, optional mobile number), the content you create (tasks, notes, files,
        messages, ledger entries), and operational records (login history with IP and device, audit logs of
        administrative actions).
      </p>
      <h2 className="text-lg font-semibold text-white">What we deliberately do not do</h2>
      <ul className="list-disc space-y-2 pl-5">
        <li>Call audio and video are never recorded or stored by the server — calls are peer-to-peer.</li>
        <li>Administrators see call and chat <i>records</i> (who, when, how long) — never message content.</li>
        <li>Internal staff notes about an account are never visible to that account.</li>
        <li>Mobile numbers are never searchable by other users.</li>
        <li>We do not sell your data. Ever.</li>
      </ul>
      <h2 className="text-lg font-semibold text-white">How your data is protected</h2>
      <p>
        Passwords are hashed with bcrypt, project passwords and reset codes likewise. Uploads are screened
        against executable and script content. Access control is enforced server-side on every request, and
        downloads are always served safely as attachments.
      </p>
      <h2 className="text-lg font-semibold text-white">Emails &amp; notifications</h2>
      <p>
        We send transactional email (verification codes, reminders, daily reports) only to your verified
        address, and only where you have the matching preference enabled. Push notifications can be enabled
        and disabled per device at any time.
      </p>
      <h2 className="text-lg font-semibold text-white">Your rights</h2>
      <p>
        You can export your content, correct your profile, and request deletion of your account. Identity
        changes (email, username) go through a verified-request flow to protect you from takeover.
      </p>
      <h2 className="text-lg font-semibold text-white">Contact</h2>
      <p>
        Privacy questions: <a className="text-[#6CE9FF] hover:underline" href="mailto:privacy@netvork.app">privacy@netvork.app</a>
      </p>
    </SitePage>
  )
}
