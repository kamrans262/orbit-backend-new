FROM node:22-alpine
WORKDIR /usr/src/orbit-presence
COPY package.json ./
RUN npm install --omit=dev --ignore-scripts --no-audit --no-fund
COPY contracts.mjs server.mjs ./
USER node
EXPOSE 8443
CMD ["node", "server.mjs"]
