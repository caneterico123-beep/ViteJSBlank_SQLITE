import { Link } from 'react-router-dom'
import AuthLayout from '../components/AuthLayout.jsx'

function NotFoundPage() {
  return (
    <AuthLayout
      eyebrow="Page not found"
      title="That page wandered off"
      subtitle="The address may be incorrect, or the page may have moved."
    >
      <Link
        to="/login"
        className="submit-button flex h-[48px] w-full items-center justify-center rounded-[9px] px-4 text-[14px] font-bold text-white no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600"
      >
        Return to sign in
      </Link>
    </AuthLayout>
  )
}

export default NotFoundPage