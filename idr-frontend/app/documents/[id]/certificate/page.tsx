import CertificateView from "./CertificateView";

export default async function CertificatePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <CertificateView key={id} id={id} />;
}
