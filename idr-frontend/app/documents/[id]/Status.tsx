"use client";

import { useEffect, useState } from "react";
import { getProcessingStatus, type DocumentDetails, type ProcessingStatus, type ReviewStatus } from "../../lib/api";

const PROCESSING_TEXT: Record<ProcessingStatus, string> = {
  PENDING: "Being prepared for AI search…",
  EMBEDDED: "Ready for AI search",
  FAILED: "AI processing failed",
};

/** Review and AI-processing status. The backend only shows this to the uploader and admins. */
export default function Status({ id, initial }: { id: string; initial: DocumentDetails }) {
  const [review, setReview] = useState<ReviewStatus | undefined>(initial.reviewStatus);
  const [processing, setProcessing] = useState<ProcessingStatus | undefined>(initial.processingStatus);
  const [reason, setReason] = useState(initial.rejectionReason ?? "");
  const [error, setError] = useState("");

  // Poll while the file is still being embedded.
  useEffect(() => {
    if (processing !== "PENDING") return;
    const timer = setInterval(() => {
      getProcessingStatus(id)
        .then(s => { setProcessing(s.processingStatus); setReview(s.reviewStatus); setReason(s.rejectionReason ?? ""); setError(s.error ?? ""); })
        .catch(() => { /* keep the last known status */ });
    }, 4000);
    return () => clearInterval(timer);
  }, [id, processing]);

  return <div className="section-toolbar" style={{ marginTop: 20, marginBottom: 0, flexWrap: "wrap", justifyContent: "flex-start" }}>
    {review && <span className={`status-badge ${review}`}>{review === "pending" ? "Awaiting review" : review}</span>}
    {processing && <span className="status-badge">{PROCESSING_TEXT[processing]}</span>}
    {review === "rejected" && reason && <p className="feedback-error" style={{ width: "100%" }}>Reason: {reason}</p>}
    {processing === "FAILED" && error && <p className="feedback-error" style={{ width: "100%" }}>{error}</p>}
  </div>;
}
