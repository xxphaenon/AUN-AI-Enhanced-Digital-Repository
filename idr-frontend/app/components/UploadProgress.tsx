/**
 * Circular upload progress. The ring fills as the file is sent; once it is all
 * sent the ring spins while the server saves and checks the file.
 */
export default function UploadProgress({ fraction, fileName, fileSize }: { fraction: number; fileName: string; fileSize: number }) {
  const sent = Math.min(1, Math.max(0, fraction));
  const saving = sent >= 1;
  const percent = Math.round(sent * 100);
  const radius = 26;
  const circumference = 2 * Math.PI * radius;
  const mb = (bytes: number) => `${(bytes / 1024 / 1024).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0)} MB`;

  return (
    <div className="upload-progress" role="progressbar" aria-label="Upload progress" aria-valuemin={0} aria-valuemax={100}
      aria-valuenow={saving ? undefined : percent} aria-valuetext={saving ? "Upload complete, processing" : `${percent}% uploaded`}>
      <svg className={saving ? "upload-ring saving" : "upload-ring"} viewBox="0 0 64 64" width="64" height="64" aria-hidden="true">
        <circle className="upload-ring-track" cx="32" cy="32" r={radius} />
        <circle className="upload-ring-fill" cx="32" cy="32" r={radius}
          strokeDasharray={circumference}
          strokeDashoffset={saving ? circumference * 0.72 : circumference * (1 - sent)} />
      </svg>
      <span className="upload-ring-label" aria-hidden="true">{saving ? "✓" : `${percent}%`}</span>
      <div className="upload-progress-text">
        <strong>{saving ? "Processing your file…" : "Uploading…"}</strong>
        <span>{fileName} · {saving ? mb(fileSize) : `${mb(fileSize * sent)} of ${mb(fileSize)}`}</span>
        {saving && <span>Checking and saving it. This takes a few seconds.</span>}
      </div>
    </div>
  );
}
