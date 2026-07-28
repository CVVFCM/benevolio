{{/*
Expand the name of the chart.
*/}}
{{- define "benevolio.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a fully qualified app name.
*/}}
{{- define "benevolio.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Chart name and version label value.
*/}}
{{- define "benevolio.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels.
*/}}
{{- define "benevolio.labels" -}}
helm.sh/chart: {{ include "benevolio.chart" . }}
{{ include "benevolio.selectorLabels" . }}
app.kubernetes.io/version: {{ .Values.image.tag | default .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels.
*/}}
{{- define "benevolio.selectorLabels" -}}
app.kubernetes.io/name: {{ include "benevolio.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
Web component selector labels.
*/}}
{{- define "benevolio.web.selectorLabels" -}}
{{ include "benevolio.selectorLabels" . }}
app.kubernetes.io/component: web
{{- end }}

{{/*
Container env for the bundled DB: pull the Bitnami-generated password from its Secret
and compose DATABASE_URL with k8s $(VAR) expansion. PGPASSWORD must precede DATABASE_URL
so the runtime substitution resolves. Empty unless postgresql.enabled.
*/}}
{{- define "benevolio.dbEnv" -}}
{{- if .Values.postgresql.enabled }}
- name: PGPASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ .Values.postgresql.auth.existingSecret | default (printf "%s-postgresql" .Release.Name) }}
      key: {{ .Values.postgresql.auth.secretKeys.userPasswordKey | default "password" }}
- name: DATABASE_URL
  value: {{ printf "postgresql://%s:$(PGPASSWORD)@%s-postgresql:5432/%s?serverVersion=%s&charset=utf8" .Values.postgresql.auth.username .Release.Name .Values.postgresql.auth.database .Values.externalDatabase.serverVersion | quote }}
{{- end }}
{{- end }}
