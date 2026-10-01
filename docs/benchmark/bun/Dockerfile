FROM oven/bun:1-alpine
WORKDIR /app
COPY package.json ./
RUN bun install --production
COPY *.ts ./
ENV ENTRY=bunsql.ts
CMD ["sh", "-c", "if [ \"$1\" = gerar ]; then exec bun run $ENTRY \"$@\"; else exec bun run cluster.ts; fi", "--"]
