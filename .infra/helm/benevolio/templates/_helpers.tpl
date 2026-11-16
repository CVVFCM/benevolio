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
Gotenberg component selector labels.
*/}}
{{- define "benevolio.gotenberg.selectorLabels" -}}
{{ include "benevolio.selectorLabels" . }}
app.kubernetes.io/component: gotenberg
{{- end }}

{{/*
Name of the CNPG Cluster. Its read-write Service is <name>-rw and the credentials
CNPG generates for the application owner are in the Secret <name>-app.
*/}}
{{- define "benevolio.database.clusterName" -}}
{{ include "benevolio.fullname" . }}-db
{{- end }}

{{/*
DATABASE_URL, built from the Secret CNPG generates. Kubernetes expands $(VAR) only
from variables declared EARLIER in the same env list, so the order is load-bearing.
`uri` is used rather than assembling user/password by hand because CNPG builds it
with proper URL escaping; it carries no query string, so Doctrine's parameters are
appended here.
*/}}
{{- define "benevolio.database.env" -}}
- name: CNPG_APP_URI
  valueFrom:
    secretKeyRef:
      name: {{ include "benevolio.database.clusterName" . }}-app
      key: uri
- name: DATABASE_URL
  value: "$(CNPG_APP_URI)?serverVersion={{ .Values.database.postgresMajor }}&charset=utf8"
{{- end }}
